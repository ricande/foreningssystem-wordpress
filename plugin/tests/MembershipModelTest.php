<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use DateTimeImmutable;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\CompanyMemberships;
use Foreningssystem\Application\People\GuardianService;
use Foreningssystem\Application\People\PeopleService;
use Foreningssystem\Application\People\PersonalIdentityService;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Domain\Guardian\GuardianApproval;
use Foreningssystem\Domain\Guardian\GuardianRelationship;
use Foreningssystem\Domain\Guardian\GuardianRepository;
use Foreningssystem\Domain\Identity\PersonalIdentityNumber;
use Foreningssystem\Domain\Identity\PersonalIdentityRecord;
use Foreningssystem\Domain\Identity\PersonalIdentityRepository;
use Foreningssystem\Domain\Meeting\AuditLog;
use Foreningssystem\Domain\Membership\Age;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Domain\Organization\Organization;
use Foreningssystem\Domain\Organization\OrganizationNumber;
use Foreningssystem\Domain\Organization\OrganizationRepository;
use Foreningssystem\Infrastructure\Persistence\MembershipAggregateSchemaMigration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MembershipModelTest extends TestCase
{
    public function test_returning_member_keeps_one_number_and_two_periods(): void
    {
        $service = $this->people(true);
        $personId = $service->register('Ada', 'Andersson', 'ada@example.test', 'M-00421', 'ordinary', AssociationDate::fromIso('2018-03-01'));
        $first = (int) $service->listPeople()[0]->membership()?->id();
        $service->endMembership($first, AssociationDate::fromIso('2021-12-31'));
        $accountId = (int) $service->listPeople()[0]->account()?->id();
        $service->addPeriod($accountId, AssociationDate::fromIso('2024-05-15'));

        self::assertSame('M-00421', $service->listPeople()[0]->membershipNumber());
        self::assertSame(1, $service->activeMembershipCount(AssociationDate::fromIso('2026-09-24')));
        self::assertSame(1, $service->activeMemberCount(AssociationDate::fromIso('2026-09-24')));

        $this->expectException(MembershipRuleException::class);
        $service->register('Bo', 'Berg', 'bo@example.test', 'M-00421', 'ordinary', AssociationDate::fromIso('2025-01-01'));
    }

    public function test_family_participants_stay_separate_people_and_a_contact_is_not_a_member(): void
    {
        [$service, $memberships, $people] = $this->world(true);
        $primary = $service->register('Karin', 'Andersson', 'karin@example.test', 'F-0042', 'family', AssociationDate::fromIso('2024-01-01'));
        $accountId = (int) $service->listPeople()[0]->account()?->id();
        $child = $service->addPersonToMembership($accountId, 'Lisa', 'Andersson', 'lisa@example.test', AssociationDate::fromIso('2012-04-17'), AssociationDate::fromIso('2026-09-24'), ParticipantRole::Member, false);
        $contact = $people->add(new \Foreningssystem\Domain\Person\Person(null, 'Ekonomi', 'Kontor', 'ekonomi@example.test', \Foreningssystem\Domain\Person\PersonStatus::Known, null));
        $company = $memberships->addMembership(new \Foreningssystem\Domain\Membership\Membership(null, 'C-1', \Foreningssystem\Domain\Membership\MembershipKind::Company, 1));
        $memberships->add(new \Foreningssystem\Domain\Membership\MembershipPeriod(null, (int) $company->id(), \Foreningssystem\Domain\Membership\MembershipStatus::Active, AssociationDate::fromIso('2026-01-01'), null, 'company'));
        $service->addParticipant((int) $company->id(), (int) $contact->id(), ParticipantRole::Contact, false, AssociationDate::fromIso('2026-09-24'));

        self::assertNotSame($primary, $child);
        self::assertSame(2, $service->activeMembershipCount(AssociationDate::fromIso('2026-09-24')));
        self::assertSame(2, $service->activeMemberCount(AssociationDate::fromIso('2026-09-24')));
        self::assertCount(2, $memberships->allMemberships());
        self::assertCount(3, $people->all());
    }

    public function test_a_family_member_can_later_have_an_ordinary_membership_without_a_new_person_row_when_added_explicitly(): void
    {
        [$service, , $people] = $this->world(true);
        $lisa = $service->register('Lisa', 'Andersson', 'lisa@example.test', 'F-1', 'family', AssociationDate::fromIso('2020-01-01'));
        $periodId = (int) $service->listPeople()[0]->membership()?->id();
        $service->endMembership($periodId, AssociationDate::fromIso('2023-12-31'));
        $service->openForExistingPerson($lisa, 'M-99', 'ordinary', AssociationDate::fromIso('2024-01-01'));

        self::assertCount(1, $people->all());
        self::assertSame($lisa, $service->listPeople()[0]->person()->id());
    }

    public function test_company_contact_is_not_an_individual_member(): void
    {
        [$people, $memberships, $organizations] = $this->stores();
        $companies = new CompanyMemberships($organizations, $memberships, $people, $this->authorizer(true), $this->transaction());
        $contact = $people->add(new \Foreningssystem\Domain\Person\Person(null, 'Kim', 'Kontakt', 'kim@example.test', \Foreningssystem\Domain\Person\PersonStatus::Known, null));
        $membershipId = $companies->register('Exempel AB', $this->organizationNumber(), '', '', 'C-1', AssociationDate::fromIso('2024-01-01'), (int) $contact->id());
        $service = new PeopleService($people, $memberships, new MembershipLedger(), $this->authorizer(true), $this->transaction(), new RecordingOpenAssignments());

        self::assertSame(1, $service->activeMembershipCount(AssociationDate::fromIso('2026-09-24')));
        self::assertSame(0, $service->activeMemberCount(AssociationDate::fromIso('2026-09-24')));
        self::assertGreaterThan(0, $membershipId);

        $this->expectException(MembershipRuleException::class);
        $service->addParticipant($membershipId, (int) $contact->id(), ParticipantRole::Member, false, AssociationDate::fromIso('2026-09-24'));
    }

    public function test_age_uses_the_explicit_birth_date_and_rejects_a_future_date(): void
    {
        $today = AssociationDate::fromIso('2026-09-24');

        self::assertSame(14, Age::years(AssociationDate::fromIso('2012-04-17'), $today));
        self::assertSame(17, Age::years(AssociationDate::fromIso('2008-09-25'), $today));
        self::assertSame(18, Age::years(AssociationDate::fromIso('2008-09-24'), $today));

        $service = $this->people(true);
        $this->expectException(InvalidArgumentException::class);
        $service->register('Uno', 'Ung', '', 'Y-1', 'youth', $today, AssociationDate::fromIso('2026-09-25'), $today);
    }

    public function test_personal_identity_numbers_are_normalized_masked_and_kept_out_of_the_audit_text(): void
    {
        $today = AssociationDate::fromIso('2026-09-24');
        $number = PersonalIdentityNumber::parse('120417-0011', $today);

        self::assertSame('20120417-0011', $number->canonical());
        self::assertSame('2012••••-0011', $number->masked());
        self::assertSame('20120417-0011', PersonalIdentityNumber::parse('201204170011', $today)->canonical());

        [$people] = $this->stores();
        $person = $people->add(new \Foreningssystem\Domain\Person\Person(null, 'Lisa', 'Andersson', '', \Foreningssystem\Domain\Person\PersonStatus::Known, null, AssociationDate::fromIso('2012-04-17')));
        $audit = new MemoryAuditLog();
        $identities = new MemoryPersonalIdentityRepository();
        $allowed = new PersonalIdentityService($identities, $people, $this->authorizer(true, [
            Capabilities::EDIT_PERSONAL_IDENTITY_NUMBERS,
            Capabilities::VIEW_PERSONAL_IDENTITY_NUMBERS,
        ]), $audit);
        $allowed->store((int) $person->id(), '20120417-0011', 'Association administration', 'Association policy', AssociationDate::fromIso('2026-09-24'), $today, 4);

        self::assertSame('20120417-0011', $allowed->reveal((int) $person->id()));
        self::assertSame('2012••••-0011', $allowed->masked((int) $person->id()));
        $event = $audit->forObject('personal_identity', 1)[0];
        self::assertSame('identity_added', $event->action());
        self::assertStringNotContainsString('20120417-0011', $event->action() . $event->objectType());

        $denied = new PersonalIdentityService($identities, $people, $this->authorizer(true, [Capabilities::EDIT_MEMBERS]), $audit);
        self::assertNull($denied->masked((int) $person->id()));
    }

    public function test_a_child_can_have_several_guardians_and_a_withdrawn_approval_stays(): void
    {
        [$people] = $this->stores();
        $child = $people->add(new \Foreningssystem\Domain\Person\Person(null, 'Lisa', 'Andersson', '', \Foreningssystem\Domain\Person\PersonStatus::Known, null));
        $anna = $people->add(new \Foreningssystem\Domain\Person\Person(null, 'Anna', 'Andersson', '', \Foreningssystem\Domain\Person\PersonStatus::Known, null));
        $bosse = $people->add(new \Foreningssystem\Domain\Person\Person(null, 'Bosse', 'Berg', '', \Foreningssystem\Domain\Person\PersonStatus::Known, null));
        $guardians = new MemoryGuardianRepository();
        $audit = new MemoryAuditLog();
        $service = new GuardianService($guardians, $people, $this->authorizer(true), $audit);
        $service->relate((int) $child->id(), (int) $anna->id(), 'parent', null);
        $service->relate((int) $child->id(), (int) $bosse->id(), 'parent', null);
        $approval = $service->approve((int) $child->id(), (int) $anna->id(), 'Storage of personal identity number', 'Association policy', new DateTimeImmutable('2026-09-24 10:00:00'), 'Signed paper form', '2026-01', '', 4);
        $service->withdraw($approval, new DateTimeImmutable('2026-10-01 10:00:00'), 4);
        $stored = $guardians->findApproval($approval);

        self::assertCount(2, $guardians->relationshipsForChild((int) $child->id()));
        self::assertNotNull($stored?->withdrawnAt());
        self::assertSame('guardian_approval_withdrawn', $audit->forObject('guardian_approval', $approval)[1]->action());
    }

    public function test_role_bundles_do_not_include_personal_identity_capabilities(): void
    {
        foreach (RoleBundles::defaults() as $capabilities) {
            self::assertNotContains(Capabilities::VIEW_PERSONAL_IDENTITY_NUMBERS, $capabilities);
            self::assertNotContains(Capabilities::EDIT_PERSONAL_IDENTITY_NUMBERS, $capabilities);
        }

        self::assertContains(Capabilities::VIEW_PERSONAL_IDENTITY_NUMBERS, Capabilities::all());
    }

    public function test_schema_15_keeps_each_legacy_number_as_its_own_membership(): void
    {
        self::assertSame(15, (new MembershipAggregateSchemaMigration('wp_', ''))->version());
        self::assertSame(16, (new \Foreningssystem\Infrastructure\Persistence\ParticipantIntervalSchemaMigration('wp_', ''))->version());
        self::assertSame('ordinary', MembershipAggregateSchemaMigration::kindFromLegacy('ordinarie'));
        self::assertSame('youth', MembershipAggregateSchemaMigration::kindFromLegacy('ungdom'));
        self::assertSame('ordinary', MembershipAggregateSchemaMigration::kindFromLegacy('okänd typ'));
        self::assertStringContainsString('UNIQUE KEY membership_number', (new MembershipAggregateSchemaMigration('wp_', ''))->statements());
    }

    public function test_youth_membership_does_not_require_a_personal_identity_number(): void
    {
        $service = $this->people(true);
        $personId = $service->register('Uno', 'Ung', '', 'Y-1', 'youth', AssociationDate::fromIso('2024-01-01'), AssociationDate::fromIso('2012-04-17'), AssociationDate::fromIso('2026-09-24'));

        self::assertSame(MembershipKind::Youth, $service->listPeople()[0]->account()?->kind());
        self::assertSame('2012-04-17', $service->listPeople()[0]->person()->birthDate()?->iso());
        self::assertSame($personId, $service->listPeople()[0]->person()->id());
    }

    /**
     * @param list<string>|null $capabilities
     */
    private function authorizer(bool $allowed, ?array $capabilities = null): Authorizer
    {
        return new class ($allowed, $capabilities) implements Authorizer {
            /** @param list<string>|null $capabilities */
            public function __construct(private bool $allowed, private ?array $capabilities)
            {
            }

            public function allows(string $capability): bool
            {
                if (! $this->allowed) {
                    return false;
                }

                return $this->capabilities === null || in_array($capability, $this->capabilities, true);
            }
        };
    }

    private function transaction(): Transaction
    {
        return new class implements Transaction {
            public function run(callable $callback): mixed
            {
                return $callback();
            }
        };
    }

    private function people(bool $allowed): PeopleService
    {
        [$people, $memberships] = $this->stores();

        return new PeopleService($people, $memberships, new MembershipLedger(), $this->authorizer($allowed), $this->transaction(), new RecordingOpenAssignments());
    }

    /**
     * @return array{0: PeopleService, 1: MemoryMembershipRepository, 2: MemoryPersonRepository}
     */
    private function world(bool $allowed): array
    {
        [$people, $memberships] = $this->stores();
        $service = new PeopleService($people, $memberships, new MembershipLedger(), $this->authorizer($allowed), $this->transaction(), new RecordingOpenAssignments());

        return [$service, $memberships, $people];
    }

    /**
     * @return array{0: MemoryPersonRepository, 1: MemoryMembershipRepository, 2: MemoryOrganizationRepository}
     */
    private function stores(): array
    {
        return [new MemoryPersonRepository(), new MemoryMembershipRepository(), new MemoryOrganizationRepository()];
    }

    private function organizationNumber(): string
    {
        $nine = '555555555';
        $sum = 0;

        for ($index = 0; $index < 9; $index++) {
            $digit = (int) $nine[$index];

            if ($index % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $nine . ((string) ((10 - ($sum % 10)) % 10));
    }
}

final class MemoryPersonalIdentityRepository implements PersonalIdentityRepository
{
    /** @var array<int, PersonalIdentityRecord> */
    public array $records = [];

    private int $nextId = 1;

    public function findForPerson(int $personId): ?PersonalIdentityRecord
    {
        foreach ($this->records as $record) {
            if ($record->personId() === $personId) {
                return $record;
            }
        }

        return null;
    }

    public function save(PersonalIdentityRecord $record): PersonalIdentityRecord
    {
        $saved = $record->id() === null ? $record->withId($this->nextId++) : $record;
        $this->records[(int) $saved->id()] = $saved;

        return $saved;
    }

    public function remove(int $personId): void
    {
        foreach ($this->records as $id => $record) {
            if ($record->personId() === $personId) {
                unset($this->records[$id]);
            }
        }
    }
}

final class MemoryOrganizationRepository implements OrganizationRepository
{
    /** @var array<int, Organization> */
    public array $organizations = [];

    private int $nextId = 1;

    public function add(Organization $organization): Organization
    {
        $saved = $organization->withId($this->nextId++);
        $this->organizations[(int) $saved->id()] = $saved;

        return $saved;
    }

    public function find(int $id): ?Organization
    {
        return $this->organizations[$id] ?? null;
    }

    public function findByNumber(string $canonical): ?Organization
    {
        foreach ($this->organizations as $organization) {
            if ($organization->number()?->canonical() === $canonical) {
                return $organization;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_values($this->organizations);
    }
}

final class MemoryGuardianRepository implements GuardianRepository
{
    /** @var array<int, GuardianRelationship> */
    public array $relationships = [];

    /** @var array<int, GuardianApproval> */
    public array $approvals = [];

    private int $nextRelationship = 1;

    private int $nextApproval = 1;

    public function addRelationship(GuardianRelationship $relationship): GuardianRelationship
    {
        $saved = $relationship->withId($this->nextRelationship++);
        $this->relationships[(int) $saved->id()] = $saved;

        return $saved;
    }

    public function saveRelationship(GuardianRelationship $relationship): void
    {
        $this->relationships[(int) $relationship->id()] = $relationship;
    }

    public function relationshipsForChild(int $childPersonId): array
    {
        return array_values(array_filter($this->relationships, static fn (GuardianRelationship $relationship): bool => $relationship->childPersonId() === $childPersonId));
    }

    public function relationshipsForGuardian(int $guardianPersonId): array
    {
        return array_values(array_filter($this->relationships, static fn (GuardianRelationship $relationship): bool => $relationship->guardianPersonId() === $guardianPersonId));
    }

    public function addApproval(GuardianApproval $approval): GuardianApproval
    {
        $saved = $approval->withId($this->nextApproval++);
        $this->approvals[(int) $saved->id()] = $saved;

        return $saved;
    }

    public function saveApproval(GuardianApproval $approval): void
    {
        $this->approvals[(int) $approval->id()] = $approval;
    }

    public function approvalsForChild(int $childPersonId): array
    {
        return array_values(array_filter($this->approvals, static fn (GuardianApproval $approval): bool => $approval->childPersonId() === $childPersonId));
    }

    public function approvalsForGuardian(int $guardianPersonId): array
    {
        return array_values(array_filter($this->approvals, static fn (GuardianApproval $approval): bool => $approval->guardianPersonId() === $guardianPersonId));
    }

    public function findApproval(int $id): ?GuardianApproval
    {
        return $this->approvals[$id] ?? null;
    }
}
