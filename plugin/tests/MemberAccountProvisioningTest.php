<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Account\AccountOutcome;
use Foreningssystem\Application\Account\MemberAccountProvisioning;
use Foreningssystem\Application\Account\ProvisioningResult;
use Foreningssystem\Application\Account\WordPressAccountGateway;
use Foreningssystem\Application\Document\MemberDocumentAccess;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\MemberExchange;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\Membership;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipParticipant;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;
use Foreningssystem\Domain\Person\PersonStatus;
use PHPUnit\Framework\TestCase;

final class MemberAccountProvisioningTest extends TestCase
{
    private AssociationDate $on;

    protected function setUp(): void
    {
        $this->on = AssociationDate::fromIso('2026-09-25');
    }

    public function test_an_active_adult_with_a_unique_email_is_provisioned(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $this->member($people, $memberships, 'Anna', 'Andersson', 'anna@example.test', '1990-05-01');

        $result = $service->provision((int) $person->id(), $this->on);

        self::assertSame(AccountOutcome::Created, $result->outcome);
        self::assertSame($result->wordpressUserId, $people->find((int) $person->id())?->wordpressUserId());
        self::assertSame(['assoc-member-' . $person->id(), 'anna@example.test', 'Anna Andersson'], $accounts->created[0]);
    }

    public function test_a_person_who_turns_18_on_the_date_is_eligible(): void
    {
        [$service, $people, $memberships] = $this->stack();
        $adult = $this->member($people, $memberships, 'Ada', 'Adult', 'ada-18@example.test', '2008-09-25', 'M-18');
        $youth = $people->add(new Person(null, 'Yngve', 'Vuxen', 'yngve-18@example.test', PersonStatus::Known, null, AssociationDate::fromIso('2008-09-25')));
        $memberships->grant((int) $youth->id(), 'M-YOUTH-18', 'youth', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null, MembershipKind::Youth);

        self::assertSame(AccountOutcome::Created, $service->provision((int) $adult->id(), $this->on)->outcome);
        self::assertSame(AccountOutcome::Created, $service->provision((int) $youth->id(), $this->on)->outcome);
    }

    public function test_a_known_17_year_old_is_not_provisioned(): void
    {
        [$service, $people, $memberships] = $this->stack();
        $person = $this->member($people, $memberships, 'Lisa', 'Lind', 'lisa@example.test', '2008-09-26');

        $result = $service->provision((int) $person->id(), $this->on);

        self::assertSame(AccountOutcome::KnownMinor, $result->outcome);
        self::assertNull($people->find((int) $person->id())?->wordpressUserId());
    }

    public function test_a_missing_birth_date_does_not_block_provisioning(): void
    {
        [$service, $people, $memberships] = $this->stack();
        $person = $this->member($people, $memberships, 'Noa', 'Okand', 'noa@example.test', null);

        self::assertSame(AccountOutcome::Created, $service->provision((int) $person->id(), $this->on)->outcome);
    }

    public function test_an_empty_email_is_skipped(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $this->member($people, $memberships, 'Bo', 'Tom', '', null);

        self::assertSame(AccountOutcome::NoEmail, $service->provision((int) $person->id(), $this->on)->outcome);
        self::assertSame([], $accounts->created);
    }

    public function test_a_deceased_person_is_skipped(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $people->add(new Person(null, 'Nils', 'Nillson', 'nils@example.test', PersonStatus::Deceased, null, AssociationDate::fromIso('1950-01-01')));
        $memberships->grant((int) $person->id(), 'M-DEAD', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null);

        self::assertSame(AccountOutcome::Deceased, $service->provision((int) $person->id(), $this->on)->outcome);
        self::assertSame([], $accounts->created);
    }

    public function test_a_historical_member_is_skipped(): void
    {
        [$service, $people, $memberships] = $this->stack();
        $person = $people->add(new Person(null, 'Johan', 'Före', 'johan@example.test', PersonStatus::Known, null, AssociationDate::fromIso('1980-01-01')));
        $memberships->grant((int) $person->id(), 'M-OLD', 'ordinary', MembershipStatus::Ended, AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('2024-01-01'));

        self::assertSame(AccountOutcome::NotActiveMember, $service->provision((int) $person->id(), $this->on)->outcome);
    }

    public function test_a_future_member_is_skipped_until_coverage_starts(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $people->add(new Person(null, 'Karin', 'Senare', 'karin@example.test', PersonStatus::Known, null, AssociationDate::fromIso('1992-01-01')));
        $memberships->grant((int) $person->id(), 'M-FUTURE', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2026-10-01'), null);

        self::assertSame(AccountOutcome::NotActiveMember, $service->provision((int) $person->id(), $this->on)->outcome);
        self::assertSame([], $accounts->created);

        $created = $service->provision((int) $person->id(), AssociationDate::fromIso('2026-10-01'));

        self::assertSame(AccountOutcome::Created, $created->outcome);
        self::assertSame(AccountOutcome::AlreadyLinked, $service->provision((int) $person->id(), AssociationDate::fromIso('2026-10-01'))->outcome);
        self::assertCount(1, $accounts->created);
        self::assertCount(1, $accounts->notified);
    }

    public function test_a_company_contact_is_skipped(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $people->add(new Person(null, 'Kim', 'Kontakt', 'kontakt@example.test', PersonStatus::Known, null));
        $membership = $memberships->addMembership(new Membership(null, 'C-1', MembershipKind::Company, 1));
        $memberships->addParticipant(new MembershipParticipant(null, (int) $membership->id(), (int) $person->id(), ParticipantRole::Contact, true, AssociationDate::fromIso('2020-01-01'), null));
        $memberships->add(new MembershipPeriod(null, (int) $membership->id(), MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null, 'company'));

        self::assertSame(AccountOutcome::NotActiveMember, $service->provision((int) $person->id(), $this->on)->outcome);
        self::assertSame([], $accounts->created);
    }

    public function test_a_guardian_only_person_is_skipped(): void
    {
        [$service, $people, , $accounts] = $this->stack();
        $person = $people->add(new Person(null, 'Eva', 'Vardnad', 'eva@example.test', PersonStatus::Known, null, AssociationDate::fromIso('1980-01-01')));

        self::assertSame(AccountOutcome::NotActiveMember, $service->provision((int) $person->id(), $this->on)->outcome);
        self::assertSame([], $accounts->notified);
        self::assertSame([], $accounts->created);
    }

    public function test_an_adult_family_participant_is_provisioned_and_a_minor_is_not(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $adult = $people->add(new Person(null, 'Erik', 'Familj', 'erik@example.test', PersonStatus::Known, null, AssociationDate::fromIso('1991-03-03')));
        $minor = $people->add(new Person(null, 'Mio', 'Familj', 'mio@example.test', PersonStatus::Known, null, AssociationDate::fromIso('2015-03-03')));
        $memberships->grant((int) $adult->id(), 'FAM-1', 'family', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null, MembershipKind::Family);
        $membership = $memberships->findMembershipByNumber('FAM-1');
        $memberships->addParticipant(new MembershipParticipant(null, (int) $membership?->id(), (int) $minor->id(), ParticipantRole::Member, false, AssociationDate::fromIso('2020-01-01'), null));

        self::assertSame(AccountOutcome::Created, $service->provision((int) $adult->id(), $this->on)->outcome);
        self::assertSame(AccountOutcome::KnownMinor, $service->provision((int) $minor->id(), $this->on)->outcome);
        self::assertCount(1, $accounts->created);
        self::assertNull($people->find((int) $minor->id())?->wordpressUserId());
    }

    public function test_two_unlinked_people_sharing_an_email_are_not_provisioned_in_either_order(): void
    {
        $first = $this->sharedPair(['Erik', 'Anna']);
        $second = $this->sharedPair(['Anna', 'Erik']);

        foreach ([$first, $second] as $case) {
            $results = $case['service']->reconcile($this->on);

            self::assertCount(2, $results);
            self::assertSame([AccountOutcome::SharedPersonEmail, AccountOutcome::SharedPersonEmail], array_map(
                static fn (ProvisioningResult $result): AccountOutcome => $result->outcome,
                $results
            ));
            self::assertSame([], $case['accounts']->created);
        }
    }

    public function test_an_existing_wordpress_email_is_not_linked_automatically(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $accounts->seed(8, 'existing', 'nora@example.test', 'Nora Existing', ['editor']);
        $person = $this->member($people, $memberships, 'Nora', 'Ny', 'Nora@Example.test', '1990-01-01');

        $result = $service->provision((int) $person->id(), $this->on);

        self::assertSame(AccountOutcome::WordpressEmailConflict, $result->outcome);
        self::assertNull($people->find((int) $person->id())?->wordpressUserId());
        self::assertSame([], $accounts->created);
        self::assertSame([], $accounts->deleted);
        self::assertTrue($accounts->userExists(8));
    }

    public function test_an_existing_link_stays_when_another_person_later_shares_the_email(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $linked = $people->add(new Person(null, 'Anna', 'Andersson', 'familjen@example.se', PersonStatus::Known, 5, AssociationDate::fromIso('1990-01-01')));
        $accounts->seed(5, 'assoc-member-1', 'familjen@example.se', 'Anna Andersson', ['subscriber']);
        $memberships->grant((int) $linked->id(), 'M-ANNA', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null);
        $other = $this->member($people, $memberships, 'Erik', 'Andersson', 'Familjen@Example.se', '1991-01-01', 'M-ERIK');

        $results = $service->reconcile($this->on);

        self::assertSame(AccountOutcome::AlreadyLinked, $results[0]->outcome);
        self::assertSame(AccountOutcome::SharedPersonEmail, $results[1]->outcome);
        self::assertSame(5, $people->find((int) $linked->id())?->wordpressUserId());
        self::assertNull($people->find((int) $other->id())?->wordpressUserId());
        self::assertSame([], $accounts->created);
        self::assertSame([], $accounts->notified);
    }

    public function test_one_wordpress_user_cannot_be_linked_to_two_people(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $first = $this->member($people, $memberships, 'Anna', 'A', 'anna-link@example.test', '1990-01-01', 'M-A');
        $second = $this->member($people, $memberships, 'Erik', 'E', 'erik-link@example.test', '1991-01-01', 'M-E');
        $accounts->seed(19, 'officer', 'officer@example.test', 'Officer', ['assoc_secretary']);

        self::assertSame(AccountOutcome::Linked, $service->linkExisting((int) $first->id(), 19, $this->on)->outcome);
        self::assertSame(AccountOutcome::UserTaken, $service->linkExisting((int) $second->id(), 19, $this->on)->outcome);
        self::assertSame(19, $people->find((int) $first->id())?->wordpressUserId());
        self::assertNull($people->find((int) $second->id())?->wordpressUserId());
    }

    public function test_explicit_linking_does_not_require_matching_email_and_preserves_roles(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $this->member($people, $memberships, 'Anna', 'A', 'anna-link@example.test', '1990-01-01');
        $accounts->seed(19, 'officer', 'officer@example.test', 'Officer', ['assoc_secretary']);

        $result = $service->linkExisting((int) $person->id(), 19, $this->on);

        self::assertSame(AccountOutcome::Linked, $result->outcome);
        self::assertSame(19, $people->find((int) $person->id())?->wordpressUserId());
        self::assertSame(['assoc_secretary'], $accounts->roles(19));
        self::assertSame([], $accounts->notified);
        self::assertSame([], $accounts->deleted);
    }

    public function test_a_new_account_is_a_subscriber_and_the_password_is_not_returned(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $this->member($people, $memberships, 'Anna', 'Andersson', 'anna@example.test', '1990-05-01');
        $result = $service->provision((int) $person->id(), $this->on);
        $names = array_map(static fn (\ReflectionProperty $property): string => $property->getName(), (new \ReflectionClass(ProvisioningResult::class))->getProperties());
        $gateway = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/WpMemberAccountGateway.php');
        $application = (string) file_get_contents(dirname(__DIR__) . '/src/Application/Account/MemberAccountProvisioning.php');

        self::assertSame(['subscriber'], $accounts->roles((int) $result->wordpressUserId));
        self::assertNotContains('password', $names);
        self::assertStringContainsString("'role' => 'subscriber'", $gateway);
        self::assertStringContainsString('wp_generate_password', $gateway);
        self::assertStringContainsString("wp_new_user_notification(\$userId, null, 'user')", $gateway);
        self::assertStringNotContainsString('wp_insert_user', $application);
        self::assertStringNotContainsString('email_exists', $application);
        self::assertStringNotContainsString('wp_new_user_notification', $application);
        self::assertStringNotContainsString('wp_generate_password', $application);
    }

    public function test_notification_happens_only_after_the_link_and_repetition_does_not_create_another_account(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $this->member($people, $memberships, 'Anna', 'Andersson', 'anna@example.test', '1990-05-01');
        $created = $service->provision((int) $person->id(), $this->on);
        $again = $service->provision((int) $person->id(), $this->on);

        self::assertSame(AccountOutcome::Created, $created->outcome);
        self::assertSame([(int) $created->wordpressUserId], $accounts->notified);
        self::assertSame((int) $created->wordpressUserId, $people->find((int) $person->id())?->wordpressUserId());
        self::assertSame(AccountOutcome::AlreadyLinked, $again->outcome);
        self::assertCount(1, $accounts->created);
        self::assertCount(1, $accounts->notified);
    }

    public function test_a_failed_person_link_removes_only_the_new_wordpress_user(): void
    {
        $people = new RejectingLinkPersonRepository(new MemoryPersonRepository());
        $memberships = new MemoryMembershipRepository();
        $accounts = new FakeWordPressAccounts();
        $accounts->people = $people;
        $accounts->seed(8, 'existing', 'existing@example.test', 'Existing', ['editor']);
        $service = new MemberAccountProvisioning($people, $memberships, $accounts, new AllowMemberEdits());
        $person = $this->member($people, $memberships, 'Anna', 'Andersson', 'anna@example.test', '1990-05-01');

        $result = $service->provision((int) $person->id(), $this->on);

        self::assertSame(AccountOutcome::Failed, $result->outcome);
        self::assertNull($people->find((int) $person->id())?->wordpressUserId());
        self::assertSame([100], $accounts->deleted);
        self::assertNotContains(8, $accounts->deleted);
        self::assertTrue($accounts->userExists(8));
        self::assertFalse($accounts->userExists(100));
        self::assertSame([], $accounts->notified);
    }

    public function test_unlinking_clears_only_the_person_link(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $this->member($people, $memberships, 'Anna', 'Andersson', 'anna@example.test', '1990-05-01');
        $created = $service->provision((int) $person->id(), $this->on);
        $userId = (int) $created->wordpressUserId;
        $accounts->roleMap[$userId] = ['assoc_secretary'];

        $result = $service->unlink((int) $person->id());

        self::assertSame(AccountOutcome::Unlinked, $result->outcome);
        self::assertNull($people->find((int) $person->id())?->wordpressUserId());
        self::assertSame('anna@example.test', $people->find((int) $person->id())?->email());
        self::assertTrue($accounts->userExists($userId));
        self::assertSame(['assoc_secretary'], $accounts->roles($userId));
        self::assertSame([], $accounts->deleted);
    }

    public function test_ending_and_reopening_membership_reuses_the_account_and_member_access(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $this->member($people, $memberships, 'Anna', 'Andersson', 'anna@example.test', '1990-05-01');
        $created = $service->provision((int) $person->id(), $this->on);
        $userId = (int) $created->wordpressUserId;
        $access = new MemberDocumentAccess($people, $memberships);
        $period = $memberships->periodsForMembership((int) $memberships->findMembershipByNumber('M-1')?->id())[0];
        $memberships->save(new MembershipPeriod(
            $period->id(),
            $period->membershipId(),
            MembershipStatus::Ended,
            $period->startedOn(),
            AssociationDate::fromIso('2026-09-01'),
            $period->historicalClass()
        ));

        self::assertSame($userId, $people->find((int) $person->id())?->wordpressUserId());
        self::assertTrue($accounts->userExists($userId));
        self::assertFalse($access->allows($userId, $this->on));

        $memberships->add(new MembershipPeriod(null, $period->membershipId(), MembershipStatus::Active, $this->on, null, 'ordinary'));
        $again = $service->provision((int) $person->id(), $this->on);

        self::assertSame(AccountOutcome::AlreadyLinked, $again->outcome);
        self::assertSame($userId, $people->find((int) $person->id())?->wordpressUserId());
        self::assertTrue($access->allows($userId, $this->on));
        self::assertCount(1, $accounts->created);
        self::assertCount(1, $accounts->notified);
        self::assertSame([], $accounts->deleted);
    }

    public function test_changing_a_linked_persons_email_does_not_rewrite_the_wordpress_email(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $this->member($people, $memberships, 'Anna', 'Andersson', 'anna@example.test', '1990-05-01');
        $created = $service->provision((int) $person->id(), $this->on);
        $userId = (int) $created->wordpressUserId;
        $people->save($people->find((int) $person->id())->withContact('Anna', 'Andersson', 'new@example.test', AssociationDate::fromIso('1990-05-01')));

        $status = $service->status((int) $person->id(), $this->on);

        self::assertSame('anna@example.test', $accounts->email($userId));
        self::assertTrue($status->emailMismatch);
        self::assertSame('new@example.test', $status->personEmail);
        self::assertSame('anna@example.test', $status->accountEmail);
        self::assertSame(AccountOutcome::AlreadyLinked, $service->provision((int) $person->id(), $this->on)->outcome);
        self::assertCount(1, $accounts->notified);
    }

    public function test_adding_an_email_can_create_the_account_and_resolving_a_duplicate_email_can_too(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $waiting = $this->member($people, $memberships, 'Bo', 'Tom', '', null, 'M-WAIT');
        self::assertSame(AccountOutcome::NoEmail, $service->provision((int) $waiting->id(), $this->on)->outcome);
        $people->save($people->find((int) $waiting->id())->withContact('Bo', 'Tom', 'bo@example.test', null));
        self::assertSame(AccountOutcome::Created, $service->provision((int) $waiting->id(), $this->on)->outcome);

        $one = $this->member($people, $memberships, 'Sam', 'Delad', 'shared@example.test', '1990-01-01', 'M-S1');
        $two = $this->member($people, $memberships, 'Kim', 'Delad', 'shared@example.test', '1991-01-01', 'M-S2');
        self::assertSame(AccountOutcome::SharedPersonEmail, $service->provision((int) $one->id(), $this->on)->outcome);
        $people->save($people->find((int) $two->id())->withContact('Kim', 'Delad', 'kim-unique@example.test', AssociationDate::fromIso('1991-01-01')));

        self::assertSame(AccountOutcome::Created, $service->provision((int) $one->id(), $this->on)->outcome);
        self::assertSame(AccountOutcome::Created, $service->provision((int) $two->id(), $this->on)->outcome);
        self::assertNotSame(
            $people->find((int) $one->id())?->wordpressUserId(),
            $people->find((int) $two->id())?->wordpressUserId()
        );
    }

    public function test_a_known_minor_keeps_a_historical_link_but_cannot_receive_a_new_one(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $historical = $people->add(new Person(null, 'Lisa', 'Kopplad', 'lisa-linked@example.test', PersonStatus::Known, 4, AssociationDate::fromIso('2012-04-17')));
        $accounts->seed(4, 'assoc-member-old', 'lisa-linked@example.test', 'Lisa Kopplad', ['subscriber']);
        $memberships->grant((int) $historical->id(), 'M-HIST', 'youth', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null, MembershipKind::Youth);
        $minor = $this->member($people, $memberships, 'Mio', 'Ny', 'mio-new@example.test', '2015-01-01', 'M-MINOR');
        $accounts->seed(21, 'guardian-choice', 'guardian-choice@example.test', 'Guardian Choice', ['subscriber']);

        self::assertSame(AccountOutcome::AlreadyLinked, $service->provision((int) $historical->id(), $this->on)->outcome);
        self::assertSame(4, $people->find((int) $historical->id())?->wordpressUserId());
        self::assertSame(AccountOutcome::MinorLinkRefused, $service->linkExisting((int) $minor->id(), 21, $this->on)->outcome);
        self::assertNull($people->find((int) $minor->id())?->wordpressUserId());
        self::assertSame([], $accounts->notified);
    }

    public function test_a_login_collision_does_not_hijack_the_existing_user(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $this->member($people, $memberships, 'Anna', 'Andersson', 'anna@example.test', '1990-05-01');
        $accounts->seed(50, 'assoc-member-' . $person->id(), 'other@example.test', 'Other', ['editor']);

        $result = $service->provision((int) $person->id(), $this->on);

        self::assertSame(AccountOutcome::Failed, $result->outcome);
        self::assertNull($people->find((int) $person->id())?->wordpressUserId());
        self::assertSame([], $accounts->deleted);
        self::assertSame(['editor'], $accounts->roles(50));
    }

    public function test_one_persons_failure_does_not_link_the_next_person_to_the_wrong_account(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $first = $this->member($people, $memberships, 'Anna', 'A', 'anna-fail@example.test', '1990-01-01', 'M-FAIL');
        $second = $this->member($people, $memberships, 'Erik', 'E', 'erik-ok@example.test', '1991-01-01', 'M-OK');
        $accounts->failEmail = 'anna-fail@example.test';

        $results = $service->reconcile($this->on);

        self::assertSame(AccountOutcome::Failed, $results[0]->outcome);
        self::assertSame(AccountOutcome::Created, $results[1]->outcome);
        self::assertNull($people->find((int) $first->id())?->wordpressUserId());
        self::assertSame($results[1]->wordpressUserId, $people->find((int) $second->id())?->wordpressUserId());
        self::assertNotSame($results[1]->wordpressUserId, $results[0]->wordpressUserId);
    }

    public function test_reconciliation_reads_membership_coverage_once(): void
    {
        $people = new MemoryPersonRepository();
        $inner = new MemoryMembershipRepository();
        $memberships = new CountingMembershipRepository($inner);
        $accounts = new FakeWordPressAccounts();
        $service = new MemberAccountProvisioning($people, $memberships, $accounts, new AllowMemberEdits());
        $this->member($people, $inner, 'Anna', 'A', 'anna-count@example.test', '1990-01-01', 'M-C1');
        $this->member($people, $inner, 'Erik', 'E', 'erik-count@example.test', '1991-01-01', 'M-C2');
        $this->member($people, $inner, 'Noa', 'N', 'noa-count@example.test', null, 'M-C3');

        $service->reconcile($this->on);

        self::assertSame(1, $memberships->allCalls);
    }

    public function test_import_uses_the_same_eligibility_rules(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $accounts = new FakeWordPressAccounts();
        $accounts->people = $people;
        $service = new MemberAccountProvisioning($people, $memberships, $accounts, new AllowMemberEdits());
        $sharedA = $people->add(new Person(null, 'Sam', 'Delad', 'shared-import@example.test', PersonStatus::Known, null, AssociationDate::fromIso('1990-01-01')));
        $sharedB = $people->add(new Person(null, 'Kim', 'Delad', 'shared-import@example.test', PersonStatus::Known, null, AssociationDate::fromIso('1991-01-01')));
        $exchange = new MemberExchange(
            $people,
            $memberships,
            new MembershipLedger(),
            new AllowMemberEdits(),
            new class implements Transaction {
                public function run(callable $callback): mixed
                {
                    return $callback();
                }
            },
            new MemoryOrganizationRepository(),
            $service,
            $this->on
        );
        $file = <<<'CSV'
# foreningsplugin-members 2
membership;IMP-ADULT;ordinary;
period;IMP-ADULT;active;2024-01-01;;ordinary
participant;IMP-ADULT;Ada;Adult;ada-import@example.test;1990-01-01;member;1;2024-01-01;
membership;IMP-MINOR;youth;
period;IMP-MINOR;active;2024-01-01;;youth
participant;IMP-MINOR;Lisa;Minor;lisa-import@example.test;2012-04-17;member;1;2024-01-01;
membership;IMP-SHARED;ordinary;
period;IMP-SHARED;active;2024-01-01;;ordinary
participant;IMP-SHARED;Sam;Shared;shared-import@example.test;;member;1;2024-01-01;
CSV;
        $result = $exchange->import($file);
        $again = $exchange->import($file);
        $adult = $this->personByEmail($people, 'ada-import@example.test');
        $minor = $this->personByEmail($people, 'lisa-import@example.test');

        self::assertNotSame([], $result->errors());
        self::assertStringContainsString('More than one person has this email.', implode("\n", $result->errors()));
        self::assertNotNull($adult?->wordpressUserId());
        self::assertNull($minor?->wordpressUserId());
        self::assertNull($people->find((int) $sharedA->id())?->wordpressUserId());
        self::assertNull($people->find((int) $sharedB->id())?->wordpressUserId());
        self::assertCount(1, $accounts->notified);
        self::assertSame(0, $again->created());
        self::assertCount(1, $accounts->notified);
    }

    public function test_a_live_wordpress_user_stays_already_linked(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $people->add(new Person(null, 'Anna', 'Andersson', 'anna@example.test', PersonStatus::Known, 37, AssociationDate::fromIso('1990-05-01')));
        $accounts->seed(37, 'assoc-member-1', 'anna@example.test', 'Anna Andersson', ['subscriber']);
        $memberships->grant((int) $person->id(), 'M-LIVE', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null);

        $result = $service->provision((int) $person->id(), $this->on);

        self::assertSame(AccountOutcome::AlreadyLinked, $result->outcome);
        self::assertSame(37, $people->find((int) $person->id())?->wordpressUserId());
        self::assertSame([], $accounts->created);
        self::assertSame([], $accounts->notified);
    }

    public function test_a_missing_wordpress_user_is_a_broken_link_and_reconciliation_leaves_it(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $people->add(new Person(null, 'Anna', 'Andersson', 'anna@example.test', PersonStatus::Known, 37, AssociationDate::fromIso('1990-05-01')));
        $memberships->grant((int) $person->id(), 'M-BROKEN', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null);
        $access = new MemberDocumentAccess($people, $memberships);

        $first = $service->reconcile($this->on);
        $second = $service->reconcile($this->on);

        self::assertSame(AccountOutcome::MissingWordpressUser, $first[0]->outcome);
        self::assertSame(AccountOutcome::MissingWordpressUser, $second[0]->outcome);
        self::assertSame(37, $people->find((int) $person->id())?->wordpressUserId());
        self::assertSame([], $accounts->created);
        self::assertSame([], $accounts->deleted);
        self::assertSame([], $accounts->notified);
        self::assertFalse($access->allows(9, $this->on));
        self::assertFalse($access->allows(0, $this->on));
    }

    public function test_clearing_a_broken_link_requires_permission_and_removes_only_the_person_reference(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $people->add(new Person(null, 'Anna', 'Andersson', 'anna@example.test', PersonStatus::Known, 37, AssociationDate::fromIso('1990-05-01')));
        $memberships->grant((int) $person->id(), 'M-CLEAR', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null);
        $denied = new MemberAccountProvisioning($people, $memberships, $accounts, new AllowMemberEdits(false));

        try {
            $denied->clearMissingLink((int) $person->id());
            self::fail('Clearing a broken link without permission should be refused.');
        } catch (NotAllowed) {
            self::assertSame(37, $people->find((int) $person->id())?->wordpressUserId());
        }

        $cleared = $service->clearMissingLink((int) $person->id());

        self::assertSame(AccountOutcome::BrokenLinkCleared, $cleared->outcome);
        self::assertNull($people->find((int) $person->id())?->wordpressUserId());
        self::assertSame('anna@example.test', $people->find((int) $person->id())?->email());
        self::assertSame([], $accounts->deleted);
        self::assertSame([], $accounts->notified);
        self::assertSame([], $accounts->created);
    }

    public function test_clearing_a_broken_link_stops_when_the_wordpress_user_exists_again(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $people->add(new Person(null, 'Anna', 'Andersson', 'anna@example.test', PersonStatus::Known, 37, AssociationDate::fromIso('1990-05-01')));
        $memberships->grant((int) $person->id(), 'M-RACE', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null);

        self::assertSame(AccountOutcome::MissingWordpressUser, $service->status((int) $person->id(), $this->on)->outcome);

        $accounts->seed(37, 'assoc-member-restored', 'anna@example.test', 'Anna Andersson', ['subscriber']);
        $result = $service->clearMissingLink((int) $person->id());

        self::assertSame(AccountOutcome::LinkStillPresent, $result->outcome);
        self::assertSame(37, $people->find((int) $person->id())?->wordpressUserId());
        self::assertSame(['subscriber'], $accounts->roles(37));
        self::assertSame([], $accounts->deleted);
        self::assertSame([], $accounts->notified);
    }

    public function test_provisioning_after_a_cleared_broken_link_creates_one_subscriber_and_still_respects_email_collisions(): void
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $person = $people->add(new Person(null, 'Anna', 'Andersson', 'anna@example.test', PersonStatus::Known, 37, AssociationDate::fromIso('1990-05-01')));
        $memberships->grant((int) $person->id(), 'M-AGAIN', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null);
        $service->clearMissingLink((int) $person->id());
        $created = $service->provision((int) $person->id(), $this->on);
        $again = $service->provision((int) $person->id(), $this->on);
        $newUserId = (int) $created->wordpressUserId;

        self::assertSame(AccountOutcome::Created, $created->outcome);
        self::assertNotSame(37, $newUserId);
        self::assertSame($newUserId, $people->find((int) $person->id())?->wordpressUserId());
        self::assertSame(['subscriber'], $accounts->roles($newUserId));
        self::assertSame([$newUserId], $accounts->notified);
        self::assertSame(AccountOutcome::AlreadyLinked, $again->outcome);
        self::assertSame($newUserId, $people->find((int) $person->id())?->wordpressUserId());
        self::assertCount(1, $accounts->created);
        self::assertCount(1, $accounts->notified);

        $blocked = $people->add(new Person(null, 'Bo', 'Krock', 'bo-stale@example.test', PersonStatus::Known, 44, AssociationDate::fromIso('1991-01-01')));
        $memberships->grant((int) $blocked->id(), 'M-COLLIDE', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null);
        $accounts->seed(80, 'other-bo', 'bo-stale@example.test', 'Other Bo', ['editor']);
        $service->clearMissingLink((int) $blocked->id());
        $conflict = $service->provision((int) $blocked->id(), $this->on);

        self::assertSame(AccountOutcome::WordpressEmailConflict, $conflict->outcome);
        self::assertNull($people->find((int) $blocked->id())?->wordpressUserId());
        self::assertTrue($accounts->userExists(80));
        self::assertSame(['editor'], $accounts->roles(80));
        self::assertCount(1, $accounts->notified);
    }

    public function test_linking_requires_member_edit_permission(): void
    {
        [$service] = $this->stack(false);

        $this->expectException(NotAllowed::class);
        $service->linkExisting(1, 2, $this->on);
    }

    /**
     * @return array{0: MemberAccountProvisioning, 1: MemoryPersonRepository, 2: MemoryMembershipRepository, 3: FakeWordPressAccounts}
     */
    private function stack(bool $allowed = true): array
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $accounts = new FakeWordPressAccounts();
        $accounts->people = $people;
        $service = new MemberAccountProvisioning($people, $memberships, $accounts, new AllowMemberEdits($allowed));

        return [$service, $people, $memberships, $accounts];
    }

    private function member(
        PersonRepository $people,
        MemoryMembershipRepository $memberships,
        string $first,
        string $last,
        string $email,
        ?string $birth,
        string $number = 'M-1',
    ): Person {
        $person = $people->add(new Person(
            null,
            $first,
            $last,
            $email,
            PersonStatus::Known,
            null,
            $birth === null ? null : AssociationDate::fromIso($birth)
        ));
        $memberships->grant((int) $person->id(), $number, 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null);

        return $person;
    }

    /**
     * @param list<string> $order
     * @return array{service: MemberAccountProvisioning, accounts: FakeWordPressAccounts}
     */
    private function sharedPair(array $order): array
    {
        [$service, $people, $memberships, $accounts] = $this->stack();
        $number = 1;

        foreach ($order as $first) {
            $this->member($people, $memberships, $first, 'Andersson', 'familjen@example.se', '1990-01-0' . $number, 'M-SHARE-' . $number);
            $number++;
        }

        return ['service' => $service, 'accounts' => $accounts];
    }

    private function personByEmail(MemoryPersonRepository $people, string $email): ?Person
    {
        foreach ($people->all() as $person) {
            if (strtolower($person->email()) === strtolower($email)) {
                return $person;
            }
        }

        return null;
    }
}

final class AllowMemberEdits implements Authorizer
{
    public function __construct(private readonly bool $allowed = true)
    {
    }

    public function allows(string $capability): bool
    {
        return $this->allowed && $capability === Capabilities::EDIT_MEMBERS;
    }
}

final class RejectingLinkPersonRepository implements PersonRepository
{
    public function __construct(private readonly PersonRepository $inner)
    {
    }

    public function add(Person $person): Person
    {
        return $this->inner->add($person);
    }

    public function save(Person $person): void
    {
        if ($person->wordpressUserId() !== null) {
            throw new \RuntimeException('link failed');
        }

        $this->inner->save($person);
    }

    public function find(int $id): ?Person
    {
        return $this->inner->find($id);
    }

    public function all(): array
    {
        return $this->inner->all();
    }
}

final class FakeWordPressAccounts implements WordPressAccountGateway
{
    /** @var array<string, int> */
    public array $emails = [];

    /** @var array<string, int> */
    public array $logins = [];

    /** @var array<int, string> */
    public array $names = [];

    /** @var array<int, string> */
    public array $userEmails = [];

    /** @var array<int, list<string>> */
    public array $roleMap = [];

    /** @var list<int> */
    public array $notified = [];

    /** @var list<int> */
    public array $deleted = [];

    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $created = [];

    public int $nextId = 100;

    public ?PersonRepository $people = null;

    public ?string $failEmail = null;

    public function seed(int $userId, string $login, string $email, string $name, array $roles): void
    {
        $this->logins[strtolower($login)] = $userId;
        $this->emails[strtolower($email)] = $userId;
        $this->names[$userId] = $name;
        $this->userEmails[$userId] = $email;
        $this->roleMap[$userId] = $roles;
    }

    public function findUserIdByEmail(string $email): ?int
    {
        return $this->emails[strtolower(trim($email))] ?? null;
    }

    public function findUserIdByLogin(string $login): ?int
    {
        return $this->logins[strtolower(trim($login))] ?? null;
    }

    public function userExists(int $userId): bool
    {
        return isset($this->names[$userId]);
    }

    public function displayName(int $userId): string
    {
        return $this->names[$userId] ?? '';
    }

    public function email(int $userId): string
    {
        return $this->userEmails[$userId] ?? '';
    }

    public function roles(int $userId): array
    {
        return $this->roleMap[$userId] ?? [];
    }

    public function createSubscriber(string $login, string $email, string $displayName): int
    {
        if ($this->failEmail !== null && strtolower($email) === strtolower($this->failEmail)) {
            throw new \RuntimeException('create failed');
        }

        if (isset($this->logins[strtolower($login)])) {
            throw new \RuntimeException('login exists');
        }

        $id = $this->nextId;
        $this->nextId++;
        $this->seed($id, $login, $email, $displayName, ['subscriber']);
        $this->created[] = [$login, $email, $displayName];

        return $id;
    }

    public function deleteCreatedUser(int $userId): void
    {
        $this->deleted[] = $userId;
        unset($this->names[$userId], $this->userEmails[$userId], $this->roleMap[$userId]);

        foreach ($this->logins as $login => $id) {
            if ($id === $userId) {
                unset($this->logins[$login]);
            }
        }

        foreach ($this->emails as $email => $id) {
            if ($id === $userId) {
                unset($this->emails[$email]);
            }
        }
    }

    public function notifyNewUser(int $userId): void
    {
        if ($this->people instanceof PersonRepository) {
            $linked = false;

            foreach ($this->people->all() as $person) {
                if ($person->wordpressUserId() === $userId) {
                    $linked = true;
                }
            }

            if (! $linked) {
                throw new \RuntimeException('notified before link');
            }
        }

        $this->notified[] = $userId;
    }
}
