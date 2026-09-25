<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Document\MemberDocumentAccess;
use Foreningssystem\Application\Document\WordPressIdentity;
use Foreningssystem\Application\MemberArea\MemberArea;
use Foreningssystem\Application\MemberArea\MemberAreaSnapshot;
use Foreningssystem\Application\MemberArea\MemberAreaState;
use Foreningssystem\Application\MemberArea\MemberAreaTiming;
use Foreningssystem\Domain\Identity\PersonalIdentityNumber;
use Foreningssystem\Domain\Identity\PersonalIdentityRecord;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\Membership;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipParticipant;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use Foreningssystem\Infrastructure\WordPress\MemberAreaBlock;
use PHPUnit\Framework\TestCase;

final class MemberAreaTest extends TestCase
{
    private AssociationDate $on;

    protected function setUp(): void
    {
        $this->on = AssociationDate::fromIso('2026-09-25');
    }

    public function test_a_live_linked_user_resolves_that_person_and_an_unlinked_login_does_not(): void
    {
        [$area, $people, $memberships] = $this->area(['20' => 'anna@example.test', '1' => 'admin@example.test', '81' => 'anna@example.test']);
        $anna = $this->person($people, 'Anna', 'Andersson', 'anna@example.test', 20, '1990-05-01');
        $this->person($people, 'Otto', 'Los', 'anna@example.test', null, '1991-01-01');
        $memberships->grant((int) $anna->id(), 'M-20', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null);
        $linked = $area->open(20, $this->on);
        $unlinked = $area->open(1, $this->on);
        $sameEmail = $area->open(81, $this->on);
        $loggedOut = $area->open(0, $this->on);

        self::assertSame(MemberAreaState::Linked, $linked->state);
        self::assertSame('Anna Andersson', $linked->name);
        self::assertTrue($linked->activeMember);
        self::assertSame('M-20', $linked->coverages[0]->number);
        self::assertSame(MemberAreaState::Unlinked, $unlinked->state);
        self::assertSame('', $unlinked->name);
        self::assertSame(MemberAreaState::Unlinked, $sameEmail->state);
        self::assertSame('', $sameEmail->name);
        self::assertSame([], $sameEmail->coverages);
        self::assertSame(MemberAreaState::LoggedOut, $loggedOut->state);
        self::assertFalse($unlinked->isLinked());
    }

    public function test_a_missing_wordpress_user_does_not_open_a_member_snapshot(): void
    {
        [$area, $people, $memberships, $accounts] = $this->area([]);
        $person = $this->person($people, 'Anna', 'Andersson', 'anna@example.test', 37, '1990-05-01');
        $memberships->grant((int) $person->id(), 'M-37', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null);
        $access = new MemberDocumentAccess($people, $memberships, $accounts);
        $snapshot = $area->open(37, $this->on);

        self::assertSame(MemberAreaState::LoggedOut, $snapshot->state);
        self::assertSame('', $snapshot->name);
        self::assertFalse($access->allows(37, $this->on));
    }

    public function test_an_officer_account_uses_the_same_explicit_person_link(): void
    {
        [$area, $people, $memberships] = $this->area(['50' => 'sekreterare@example.test']);
        $person = $this->person($people, 'Kim', 'Sekreterare', 'kim@example.test', 50, '1985-04-04');
        $memberships->grant((int) $person->id(), 'M-50', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2021-01-01'), null);
        $snapshot = $area->open(50, $this->on);

        self::assertSame(MemberAreaState::Linked, $snapshot->state);
        self::assertSame('Kim Sekreterare', $snapshot->name);
        self::assertTrue($snapshot->activeMember);
        self::assertTrue($snapshot->emailMismatch);
        self::assertSame('sekreterare@example.test', $snapshot->accountEmail);
        self::assertSame('kim@example.test', $snapshot->contactEmail);
    }

    public function test_ended_and_returning_and_future_coverage_stay_separate_and_ordered(): void
    {
        [$area, $people, $memberships] = $this->area(['20' => 'anna@example.test']);
        $person = $this->person($people, 'Anna', 'Andersson', 'anna@example.test', 20, '1990-05-01');
        $personId = (int) $person->id();
        $memberships->grant($personId, 'M-EARLY', 'ordinary', MembershipStatus::Ended, AssociationDate::fromIso('2018-01-01'), AssociationDate::fromIso('2019-06-01'));
        $memberships->grant($personId, 'M-LATE', 'ordinary', MembershipStatus::Ended, AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('2022-12-31'));
        $memberships->grant($personId, 'M-FUTURE', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2027-06-01'), null);
        $memberships->grant($personId, 'M-NOW', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2024-03-01'), null);
        $returning = $this->person($people, 'Bo', 'Tillbaka', 'bo@example.test', 21, '1980-01-01');
        $memberships->grant((int) $returning->id(), 'M-1024', 'ordinary', MembershipStatus::Ended, AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('2022-12-31'));
        $memberships->grant((int) $returning->id(), 'M-1024', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $areaWithBo = new MemberArea($people, $memberships, new MemoryPersonalIdentityRepository(), new PortalAccounts(['20' => 'anna@example.test', '21' => 'bo@example.test']));
        $ordered = $area->open(20, $this->on);
        $history = $areaWithBo->open(21, $this->on);
        $endedOnly = $this->area(['8' => 'bo-ended@example.test']);
        $former = $this->person($endedOnly[1], 'Bo', 'Slut', 'bo-ended@example.test', 8, '1980-01-01');
        $endedOnly[2]->grant((int) $former->id(), 'M-END', 'ordinary', MembershipStatus::Ended, AssociationDate::fromIso('2021-01-01'), AssociationDate::fromIso('2025-12-31'));
        $formerView = $endedOnly[0]->open(8, $this->on);
        $numbers = array_map(static fn ($coverage): string => $coverage->number, $ordered->coverages);

        self::assertSame(['M-NOW', 'M-FUTURE', 'M-LATE', 'M-EARLY'], $numbers);
        self::assertSame(MemberAreaTiming::Current, $ordered->coverages[0]->timing);
        self::assertSame(MemberAreaTiming::Future, $ordered->coverages[1]->timing);
        self::assertSame(MemberAreaTiming::Ended, $ordered->coverages[2]->timing);
        self::assertTrue($ordered->activeMember);
        self::assertFalse($formerView->activeMember);
        self::assertSame('M-END', $formerView->coverages[0]->number);
        self::assertSame('2021-01-01', $formerView->coverages[0]->from);
        self::assertSame('2025-12-31', $formerView->coverages[0]->to);
        self::assertTrue($history->activeMember);
        self::assertSame(['2024-01-01', '2020-01-01'], array_map(static fn ($coverage): string => $coverage->from, $history->coverages));
        self::assertSame('2022-12-31', $history->coverages[1]->to);
    }

    public function test_a_family_membership_shows_only_the_logged_in_participants_own_coverage(): void
    {
        [$area, $people, $memberships] = $this->area(['20' => 'anna@family.test']);
        $anna = $this->person($people, 'Anna', 'Berg', 'anna@family.test', 20, '1990-05-01');
        $erik = $this->person($people, 'Erik', 'Berg', 'erik@family.test', null, '1988-03-03');
        $lisa = $this->person($people, 'Lisa', 'Berg', 'lisa@family.test', null, '2014-02-02');
        $johan = $this->person($people, 'Johan', 'Berg', 'johan@family.test', null, '2015-07-07');
        $family = $memberships->addMembership(new Membership(null, 'F-1042', MembershipKind::Family, null));
        $familyId = (int) $family->id();
        $memberships->add(new \Foreningssystem\Domain\Membership\MembershipPeriod(null, $familyId, MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null, 'family'));
        $memberships->addParticipant(new MembershipParticipant(null, $familyId, (int) $anna->id(), ParticipantRole::Member, false, AssociationDate::fromIso('2025-05-01'), null));
        $memberships->addParticipant(new MembershipParticipant(null, $familyId, (int) $erik->id(), ParticipantRole::Member, true, AssociationDate::fromIso('2024-01-01'), null));
        $memberships->addParticipant(new MembershipParticipant(null, $familyId, (int) $lisa->id(), ParticipantRole::Member, false, AssociationDate::fromIso('2024-06-01'), null));
        $memberships->addParticipant(new MembershipParticipant(null, $familyId, (int) $johan->id(), ParticipantRole::Member, false, AssociationDate::fromIso('2024-01-01'), null));
        $snapshot = $area->open(20, $this->on);
        $html = MemberAreaBlock::present($snapshot, [['title' => 'Stadgar', 'url' => '/?assoc_document=1']], 'https://example.test/wp-login.php', 'https://example.test/wp-login.php?action=logout');
        $blob = json_encode($snapshot, JSON_THROW_ON_ERROR) . $html;

        self::assertSame('Anna Berg', $snapshot->name);
        self::assertCount(1, $snapshot->coverages);
        self::assertSame('F-1042', $snapshot->coverages[0]->number);
        self::assertSame(MembershipKind::Family, $snapshot->coverages[0]->kind);
        self::assertSame('2025-05-01', $snapshot->coverages[0]->from);
        self::assertNull($snapshot->coverages[0]->to);
        self::assertStringNotContainsString('2024-01-01', $blob);
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('personId', $encoded);
        foreach (['Erik', 'Lisa', 'Johan', 'erik@family.test', 'lisa@family.test', 'johan@family.test', '1988-03-03', '2014-02-02', '2015-07-07'] as $secret) {
            self::assertStringNotContainsString($secret, $blob);
        }
    }

    public function test_a_company_contact_is_not_an_individual_membership(): void
    {
        [$area, $people, $memberships] = $this->area(['30' => 'kim@example.test']);
        $contact = $this->person($people, 'Kim', 'Kontakt', 'kim@example.test', 30, '1982-02-02');
        $company = $memberships->addMembership(new Membership(null, 'C-9', MembershipKind::Company, 1));
        $companyId = (int) $company->id();
        $memberships->addParticipant(new MembershipParticipant(null, $companyId, (int) $contact->id(), ParticipantRole::Contact, true, AssociationDate::fromIso('2020-01-01'), null));
        $memberships->add(new \Foreningssystem\Domain\Membership\MembershipPeriod(null, $companyId, MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null, 'company'));
        $snapshot = $area->open(30, $this->on);

        self::assertSame(MemberAreaState::Linked, $snapshot->state);
        self::assertFalse($snapshot->activeMember);
        self::assertSame([], $snapshot->coverages);
    }

    public function test_personal_identity_presence_does_not_include_the_number_and_emails_stay_distinct(): void
    {
        [$area, $people, $memberships, , $identities] = $this->area(['20' => 'anna.old@example.test']);
        $anna = $this->person($people, 'Anna', 'Andersson', 'anna.new@example.test', 20, '1990-05-01');
        $memberships->grant((int) $anna->id(), 'M-20', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null);
        $identities->save(new PersonalIdentityRecord(
            null,
            (int) $anna->id(),
            PersonalIdentityNumber::parse('19900501-0005', $this->on),
            'member register',
            'supplied by the member',
            $this->on,
            null
        ));
        $snapshot = $area->open(20, $this->on);
        $html = MemberAreaBlock::present($snapshot, [], 'https://example.test/login', 'https://example.test/logout');

        self::assertTrue($snapshot->personalIdentityRecorded);
        self::assertTrue($snapshot->emailMismatch);
        self::assertSame('1990-05-01', $snapshot->birthDate);
        self::assertStringContainsString('Personal identity number: Recorded', $html);
        self::assertStringContainsString('Your association contact email and your WordPress account email are different.', $html);
        self::assertStringNotContainsString('19900501-0005', $html);
        self::assertStringNotContainsString('199005010005', $html);
        self::assertStringNotContainsString('900501-0005', $html);
        self::assertStringNotContainsString('0005', $html);
        self::assertStringNotContainsString('member register', $html);
    }

    public function test_a_deceased_linked_person_does_not_receive_the_portal(): void
    {
        [$area, $people, $memberships] = $this->area(['20' => 'ada@example.test']);
        $person = $people->add(new Person(null, 'Ada', 'Borta', 'ada@example.test', PersonStatus::Deceased, 20, AssociationDate::fromIso('1940-01-01')));
        $memberships->grant((int) $person->id(), 'M-OLD', 'ordinary', MembershipStatus::Ended, AssociationDate::fromIso('2000-01-01'), AssociationDate::fromIso('2020-01-01'));
        $snapshot = $area->open(20, $this->on);
        $html = MemberAreaBlock::present($snapshot, null, 'https://example.test/login', 'https://example.test/logout');

        self::assertSame(MemberAreaState::Unavailable, $snapshot->state);
        self::assertSame('', $snapshot->name);
        self::assertSame([], $snapshot->coverages);
        self::assertStringContainsString('Your member information is not available.', $html);
        self::assertStringNotContainsString('Ada', $html);
        self::assertStringNotContainsString('M-OLD', $html);
    }

    public function test_the_block_renders_login_unlinked_active_and_ended_states_without_a_person_parameter(): void
    {
        $loggedOut = MemberAreaBlock::present(MemberAreaSnapshot::loggedOut(), null, 'https://example.test/wp-login.php?redirect_to=%2Fmina-sidor%2F', 'https://example.test/logout');
        $unlinked = MemberAreaBlock::present(MemberAreaSnapshot::unlinked(), [['title' => 'Hidden', 'url' => '/secret']], 'https://example.test/login', 'https://example.test/logout');
        [$area, $people, $memberships] = $this->area(['20' => 'anna@example.test', '8' => 'bo@example.test']);
        $anna = $this->person($people, 'Anna', 'Andersson', 'anna@example.test', 20, '1990-05-01');
        $bo = $this->person($people, 'Bo', 'Slut', 'bo@example.test', 8, '1980-01-01');
        $memberships->grant((int) $anna->id(), 'M-20', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null);
        $memberships->grant((int) $bo->id(), 'M-END', 'ordinary', MembershipStatus::Ended, AssociationDate::fromIso('2021-01-01'), AssociationDate::fromIso('2025-12-31'));
        $active = MemberAreaBlock::present($area->open(20, $this->on), [['title' => 'Stadgar', 'url' => '/?assoc_document=4']], 'https://example.test/login', 'https://example.test/logout');
        $ended = MemberAreaBlock::present($area->open(8, $this->on), [['title' => 'Stadgar', 'url' => '/?assoc_document=4']], 'https://example.test/login', 'https://example.test/logout');
        $access = new MemberDocumentAccess($people, $memberships, new PortalAccounts(['20' => 'anna@example.test', '8' => 'bo@example.test', '81' => 'bo@example.test']));

        self::assertStringContainsString('Log in to view your membership.', $loggedOut);
        self::assertStringContainsString('https://example.test/wp-login.php?redirect_to=%2Fmina-sidor%2F', $loggedOut);
        self::assertStringNotContainsString('Anna', $loggedOut);
        self::assertStringContainsString('This WordPress account is not linked to a member record.', $unlinked);
        self::assertStringNotContainsString('Hidden', $unlinked);
        self::assertStringNotContainsString('We found', $unlinked);
        foreach (['My details', 'My membership', 'My documents', 'My privacy', 'My account', 'Membership status: Active', 'Stadgar', 'assoc_document=4', 'Log out'] as $part) {
            self::assertStringContainsString($part, $active);
        }
        self::assertStringContainsString('M-END', $ended);
        self::assertStringContainsString('Membership status: Not currently active', $ended);
        self::assertStringContainsString('Member-only documents are available while your individual membership is active.', $ended);
        self::assertStringNotContainsString('Stadgar', $ended);
        self::assertStringNotContainsString('assoc-member-', $active);
        self::assertTrue($access->allows(20, $this->on));
        self::assertFalse($access->allows(8, $this->on));
        self::assertFalse($access->allows(81, $this->on));
        self::assertSame(20, MemberAreaBlock::viewer(20, ['personId' => (int) $bo->id(), 'userId' => 81]));
    }

    /**
     * @param array<int, string> $accounts
     * @return array{MemberArea, MemoryPersonRepository, MemoryMembershipRepository, PortalAccounts, MemoryPersonalIdentityRepository}
     */
    private function area(array $accounts): array
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $identities = new MemoryPersonalIdentityRepository();
        $portalAccounts = new PortalAccounts($accounts);

        return [new MemberArea($people, $memberships, $identities, $portalAccounts), $people, $memberships, $portalAccounts, $identities];
    }

    private function person(MemoryPersonRepository $people, string $first, string $last, string $email, ?int $userId, string $birth): Person
    {
        return $people->add(new Person(null, $first, $last, $email, PersonStatus::Known, $userId, AssociationDate::fromIso($birth)));
    }
}

final class PortalAccounts implements WordPressIdentity
{
    /** @param array<int, string> $emails */
    public function __construct(private readonly array $emails)
    {
    }

    public function exists(int $userId): bool
    {
        return array_key_exists($userId, $this->emails);
    }

    public function email(int $userId): string
    {
        return $this->emails[$userId] ?? '';
    }
}
