<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Board\EndOpenBoardAssignments;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\MemberExchange;
use Foreningssystem\Application\People\PeopleService;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardAssignmentLedger;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Domain\Organization\Organization;
use Foreningssystem\Domain\Organization\OrganizationNumber;
use PHPUnit\Framework\TestCase;

final class MembersCorrectionTest extends TestCase
{
    public function test_participant_roles_follow_the_membership_kind(): void
    {
        [$service, $memberships] = $this->world();
        $today = AssociationDate::fromIso('2026-09-24');
        $karin = $service->register('Karin', 'Andersson', 'karin-role@example.test', 'F-100', 'family', AssociationDate::fromIso('2024-01-01'));
        $familyId = (int) $memberships->findMembershipByNumber('F-100')?->id();
        $lisa = $service->addPersonToMembership($familyId, 'Lisa', 'Andersson', 'lisa-role@example.test', AssociationDate::fromIso('2012-04-17'), AssociationDate::fromIso('2024-02-01'), ParticipantRole::Member, false, $today);
        $anna = $service->register('Anna', 'Andersson', 'anna-role@example.test', 'O-100', 'ordinary', AssociationDate::fromIso('2024-01-01'));
        $ordinaryId = (int) $memberships->findMembershipByNumber('O-100')?->id();
        $bo = $service->rememberPerson('Bo', 'Berg', 'bo-role@example.test', null, $today);
        $organizations = new MemoryOrganizationRepository();
        $saved = $organizations->add(new Organization(null, 'Exempel AB', OrganizationNumber::parse('556012-3456'), '', ''));
        $company = $memberships->addMembership(new \Foreningssystem\Domain\Membership\Membership(null, 'C-100', MembershipKind::Company, $saved->id()));
        $memberships->add(new \Foreningssystem\Domain\Membership\MembershipPeriod(null, (int) $company->id(), \Foreningssystem\Domain\Membership\MembershipStatus::Active, AssociationDate::fromIso('2025-01-01'), null, 'company'));

        $service->addParticipant((int) $company->id(), $anna, ParticipantRole::Contact, false, AssociationDate::fromIso('2025-01-01'));
        $service->endParticipation($ordinaryId, $anna, AssociationDate::fromIso('2024-06-01'));
        $service->addParticipant($ordinaryId, $anna, ParticipantRole::Member, true, AssociationDate::fromIso('2024-07-01'));

        try {
            $service->addParticipant($ordinaryId, $bo, ParticipantRole::Member, false, AssociationDate::fromIso('2024-08-01'));
            self::fail('A second person should not join an ordinary membership.');
        } catch (MembershipRuleException $error) {
            self::assertSame('An ordinary or youth membership has one member.', $error->getMessage());
        }

        try {
            $service->addParticipant($ordinaryId, $bo, ParticipantRole::Contact, false, AssociationDate::fromIso('2024-08-01'));
            self::fail('A contact does not belong on an ordinary membership.');
        } catch (MembershipRuleException $error) {
            self::assertSame('Company contacts can only be added to company memberships.', $error->getMessage());
        }

        try {
            $service->addParticipant($familyId, $bo, ParticipantRole::Contact, false, AssociationDate::fromIso('2024-08-01'));
            self::fail('A contact does not belong on a family membership.');
        } catch (MembershipRuleException $error) {
            self::assertSame('Company contacts can only be added to company memberships.', $error->getMessage());
        }

        try {
            $service->requireKind($ordinaryId, MembershipKind::Family);
            self::fail('A family action should not accept an ordinary membership.');
        } catch (MembershipRuleException $error) {
            self::assertSame('This action is only available for family memberships.', $error->getMessage());
        }

        try {
            $service->requireKind($familyId, MembershipKind::Company);
            self::fail('A company action should not accept a family membership.');
        } catch (MembershipRuleException $error) {
            self::assertSame('Company contacts can only be added to company memberships.', $error->getMessage());
        }

        self::assertGreaterThan(0, $lisa);
        self::assertGreaterThan(0, $karin);
        self::assertSame(ParticipantRole::Contact, $memberships->participantsForMembership((int) $company->id())[0]->role());
    }

    public function test_an_ended_company_membership_can_start_another_period(): void
    {
        [$service, $memberships] = $this->world();
        $organizations = new MemoryOrganizationRepository();
        $saved = $organizations->add(new Organization(null, 'Exempel AB', OrganizationNumber::parse('556012-3456'), '', ''));
        $anna = $service->rememberPerson('Anna', 'Andersson', 'anna-co@example.test', null, AssociationDate::fromIso('2026-09-24'));
        $company = $memberships->addMembership(new \Foreningssystem\Domain\Membership\Membership(null, 'C-200', MembershipKind::Company, $saved->id()));
        $companyId = (int) $company->id();
        $first = $memberships->add(new \Foreningssystem\Domain\Membership\MembershipPeriod(null, $companyId, \Foreningssystem\Domain\Membership\MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null, 'company'));
        $service->addParticipant($companyId, $anna, ParticipantRole::Contact, true, AssociationDate::fromIso('2024-01-01'));
        $service->endMembership((int) $first->id(), AssociationDate::fromIso('2024-12-31'));
        $peopleBefore = count($service->listPeople());
        $second = $service->addPeriod($companyId, AssociationDate::fromIso('2026-01-01'));
        $periods = $memberships->periodsForMembership($companyId);
        $account = $memberships->findMembership($companyId);

        self::assertCount(2, $periods);
        self::assertSame('2024-12-31', $periods[0]->endedOn()?->iso());
        self::assertNull($periods[1]->endedOn());
        self::assertSame($second, $periods[1]->id());
        self::assertSame('C-200', $account?->number());
        self::assertSame($saved->id(), $account?->organizationId());
        self::assertSame(MembershipKind::Company, $account?->kind());
        self::assertCount(1, $memberships->participantsForMembership($companyId));
        self::assertSame(ParticipantRole::Contact, $memberships->participantsForMembership($companyId)[0]->role());
        self::assertSame($peopleBefore, count($service->listPeople()));
        self::assertSame(0, $service->activeMemberCount(AssociationDate::fromIso('2026-09-24')));

        $empty = $memberships->addMembership(new \Foreningssystem\Domain\Membership\Membership(null, 'C-201', MembershipKind::Company, $saved->id()));
        $ended = $memberships->add(new \Foreningssystem\Domain\Membership\MembershipPeriod(null, (int) $empty->id(), \Foreningssystem\Domain\Membership\MembershipStatus::Active, AssociationDate::fromIso('2020-01-01'), null, 'company'));
        $service->endMembership((int) $ended->id(), AssociationDate::fromIso('2020-12-31'));
        $restarted = $service->addPeriod((int) $empty->id(), AssociationDate::fromIso('2026-02-01'));

        self::assertGreaterThan(0, $restarted);
        self::assertSame([], $memberships->participantsForMembership((int) $empty->id()));
    }

    public function test_youth_membership_requires_a_birth_date_and_does_not_invent_one(): void
    {
        [$service] = $this->world();
        $today = AssociationDate::fromIso('2026-09-24');
        $withBirth = $service->rememberPerson('Lisa', 'Andersson', 'lisa-youth@example.test', AssociationDate::fromIso('2012-04-17'), $today);
        $withoutBirth = $service->rememberPerson('Anna', 'Andersson', 'anna-youth@example.test', null, $today);

        $familyPerson = $service->rememberPerson('Kim', 'Berg', 'kim-family@example.test', null, $today);
        $service->openForExistingPerson($withBirth, 'Y-100', 'youth', AssociationDate::fromIso('2026-01-01'));
        $service->openForExistingPerson($withoutBirth, 'O-300', 'ordinary', AssociationDate::fromIso('2026-01-01'));
        $service->openForExistingPerson($familyPerson, 'F-300', 'family', AssociationDate::fromIso('2020-01-01'));

        try {
            $service->openForExistingPerson($withoutBirth, 'Y-300', 'youth', AssociationDate::fromIso('2026-01-01'));
            self::fail('Youth membership should require a birth date.');
        } catch (MembershipRuleException $error) {
            self::assertSame('Add a birth date before starting a youth membership.', $error->getMessage());
        }

        try {
            $service->register('Bo', 'Berg', 'bo-youth@example.test', 'Y-301', 'youth', AssociationDate::fromIso('2026-01-01'), null, $today);
            self::fail('A new youth member should require a birth date.');
        } catch (MembershipRuleException $error) {
            self::assertSame('Add a birth date before starting a youth membership.', $error->getMessage());
        }

        $anna = null;

        foreach ($service->listPeople() as $record) {
            if ($record->person()->email() === 'anna-youth@example.test') {
                $anna = $record->person();
            }
        }

        self::assertNotNull($anna);
        self::assertNull($anna->birthDate());
    }

    public function test_structured_import_uses_the_same_role_rules(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $exchange = new MemberExchange(
            $people,
            $memberships,
            new MembershipLedger(),
            new class implements Authorizer {
                public function allows(string $capability): bool
                {
                    return in_array($capability, [Capabilities::EDIT_MEMBERS, Capabilities::EXPORT_MEMBERS], true);
                }
            },
            new class implements Transaction {
                public function run(callable $callback): mixed
                {
                    return $callback();
                }
            },
            new MemoryOrganizationRepository()
        );
        $result = $exchange->import(<<<'CSV'
# foreningsplugin-members 2
organization;556012-3456;Exempel AB;;
membership;F-9;family;
membership;O-9;ordinary;
membership;C-9;company;556012-3456
period;F-9;active;2024-01-01;;family
period;O-9;active;2024-01-01;;ordinary
period;C-9;active;2024-01-01;;company
participant;F-9;Karin;Andersson;karin-imp@example.test;;member;1;2024-01-01;
participant;F-9;Lisa;Andersson;lisa-imp@example.test;2012-04-17;member;0;2024-02-01;
participant;O-9;Ann;One;ann-imp@example.test;;member;1;2024-01-01;
participant;O-9;Bea;Two;bea-imp@example.test;;member;0;2024-01-01;
participant;O-9;Cid;Three;cid-imp@example.test;;contact;0;2024-01-01;
participant;C-9;Ada;Contact;ada-imp@example.test;;contact;1;2024-01-01;
CSV);

        $messages = implode("\n", $result->errors());

        self::assertStringContainsString('An ordinary or youth membership has one member.', $messages);
        self::assertStringContainsString('Company contacts can only be added to company memberships.', $messages);
        self::assertSame(ParticipantRole::Member, $memberships->participantsForMembership((int) $memberships->findMembershipByNumber('F-9')?->id())[1]->role());
        self::assertSame(ParticipantRole::Contact, $memberships->participantsForMembership((int) $memberships->findMembershipByNumber('C-9')?->id())[0]->role());
        self::assertCount(1, $memberships->participantsForMembership((int) $memberships->findMembershipByNumber('O-9')?->id()));
    }

    /**
     * @return array{0: PeopleService, 1: MemoryMembershipRepository}
     */
    private function world(): array
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $service = new PeopleService(
            $people,
            $memberships,
            new MembershipLedger(),
            new class implements Authorizer {
                public function allows(string $capability): bool
                {
                    return in_array($capability, [Capabilities::EDIT_MEMBERS, Capabilities::VIEW_MEMBERS], true);
                }
            },
            new class implements Transaction {
                public function run(callable $callback): mixed
                {
                    return $callback();
                }
            },
            new EndOpenBoardAssignments(new MemoryBoardAssignmentRepository(), new BoardAssignmentLedger())
        );

        return [$service, $memberships];
    }
}
