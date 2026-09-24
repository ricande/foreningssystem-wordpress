<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\CompanyMemberships;
use Foreningssystem\Application\People\GuardianService;
use Foreningssystem\Application\People\MemberExchange;
use Foreningssystem\Application\People\PeopleService;
use Foreningssystem\Application\People\PersonalIdentityService;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Identity\PersonalIdentityNumber;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MemberCoverage;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipParticipant;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Domain\Organization\OrganizationNumber;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use Foreningssystem\Infrastructure\Persistence\LegacyMembershipGateway;
use Foreningssystem\Infrastructure\Persistence\LegacyMembershipImporter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MembershipRemediationTest extends TestCase
{
    public function test_participation_dates_bound_membership_counts_board_coverage_and_retention(): void
    {
        [$service, $memberships] = $this->people();
        $service->register('Karin', 'Andersson', 'karin-rem@example.test', 'F-REM', 'family', AssociationDate::fromIso('2024-01-01'));
        $accountId = (int) $service->listPeople()[0]->account()?->id();
        $before = $service->publicMemberCount(AssociationDate::fromIso('2025-06-01'));
        $lisa = $service->addPersonToMembership(
            $accountId,
            'Lisa',
            'Andersson',
            'lisa-rem@example.test',
            AssociationDate::fromIso('2012-04-17'),
            AssociationDate::fromIso('2026-09-24'),
            ParticipantRole::Member,
            false,
            AssociationDate::fromIso('2026-09-24')
        );

        self::assertSame($before, $service->publicMemberCount(AssociationDate::fromIso('2025-06-01')));
        self::assertSame($before + 1, $service->publicMemberCount(AssociationDate::fromIso('2026-09-24')));
        self::assertFalse($this->active($memberships, $lisa, '2025-06-01'));
        self::assertTrue($this->active($memberships, $lisa, '2026-09-24'));

        $service->endParticipation($accountId, $lisa, AssociationDate::fromIso('2027-01-01'));

        self::assertFalse($this->active($memberships, $lisa, '2027-06-01'));
        self::assertSame('2027-01-01', MemberCoverage::retentionEnd($lisa, $memberships->allParticipants(), $memberships->all())?->iso());

        $service->addParticipant($accountId, $lisa, ParticipantRole::Member, false, AssociationDate::fromIso('2028-01-01'));
        $rows = array_values(array_filter(
            $memberships->participantsForMembership($accountId),
            static fn (MembershipParticipant $participant): bool => $participant->personId() === $lisa
        ));

        self::assertCount(2, $rows);
        self::assertSame('2026-09-24', $rows[0]->startedOn()->iso());
        self::assertSame('2027-01-01', $rows[0]->endedOn()?->iso());
        self::assertSame('2028-01-01', $rows[1]->startedOn()->iso());
        self::assertNull($rows[1]->endedOn());
        self::assertTrue($this->active($memberships, $lisa, '2028-02-01'));

        $period = new MembershipPeriod(1, $accountId, MembershipStatus::Ended, AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('2020-12-31'), 'family');
        $participant = new MembershipParticipant(1, $accountId, $lisa, ParticipantRole::Member, false, AssociationDate::fromIso('2026-09-24'), null);

        self::assertFalse(MemberCoverage::isActiveMember($lisa, AssociationDate::fromIso('2026-09-24'), [$participant], [$period]));
        self::assertSame([], MemberCoverage::periodsCoveringAssignment($lisa, AssociationDate::fromIso('2025-01-01'), AssociationDate::fromIso('2025-12-31'), [$participant], [
            new MembershipPeriod(2, $accountId, MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null, 'family'),
        ]));
    }

    public function test_an_existing_person_can_join_a_family_without_a_new_person_row(): void
    {
        [$service, , $people] = $this->people();
        $karin = $service->register('Karin', 'Andersson', 'karin-link@example.test', 'F-LINK', 'family', AssociationDate::fromIso('2024-01-01'));
        $existing = $service->rememberPerson('Erik', 'Andersson', 'erik-link@example.test');
        $email = $people->find($existing)?->email();
        $accountId = (int) $service->listPeople()[0]->account()?->id();
        $service->addParticipant($accountId, $existing, ParticipantRole::Member, false, AssociationDate::fromIso('2026-01-01'));
        $created = $service->addPersonToMembership($accountId, 'Lisa', 'Andersson', 'lisa-link@example.test', null, AssociationDate::fromIso('2026-02-01'), ParticipantRole::Member, false);

        self::assertSame($email, $people->find($existing)?->email());
        self::assertCount(3, $people->all());
        self::assertNotSame($karin, $created);

        $this->expectException(MembershipRuleException::class);
        $service->addParticipant($accountId, $existing, ParticipantRole::Member, false, AssociationDate::fromIso('2026-03-01'));
    }

    public function test_a_company_contact_stays_a_contact_when_registration_rolls_back(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $organizations = new MemoryOrganizationRepository();
        $contact = $people->add(new Person(null, 'Kim', 'Kontakt', 'kim-rem@example.test', PersonStatus::Known, null));
        $companies = new CompanyMemberships($organizations, $memberships, $people, $this->authorizer(), $this->restoringTransaction($organizations, $memberships));
        $memberships->failNext = 'membership';

        try {
            $companies->register('Exempel AB', '556012-3456', '', '', 'C-REM', AssociationDate::fromIso('2024-01-01'), (int) $contact->id());
            self::fail('A failed membership insert should roll back the organization.');
        } catch (RuntimeException) {
            self::assertSame([], $organizations->organizations);
            self::assertSame([], $memberships->accounts);
        }

        $memberships->failNext = 'period';

        try {
            $companies->register('Exempel AB', '556012-3456', '', '', 'C-REM', AssociationDate::fromIso('2024-01-01'), (int) $contact->id());
            self::fail('A failed period insert should roll back the organization and membership.');
        } catch (RuntimeException) {
            self::assertSame([], $organizations->organizations);
            self::assertSame([], $memberships->accounts);
            self::assertSame([], $memberships->participants);
        }

        $companies->register('Exempel AB', '556012-3456', '', '', 'C-REM', AssociationDate::fromIso('2024-01-01'), (int) $contact->id());

        try {
            $companies->register('Annan AB', '556012-3456', '', '', 'C-REM-2', AssociationDate::fromIso('2024-02-01'), (int) $contact->id());
            self::fail('A duplicate organization number should be rejected.');
        } catch (MembershipRuleException) {
            self::assertCount(1, $organizations->organizations);
            self::assertCount(1, $memberships->accounts);
        }

        self::assertSame(0, (new PeopleService($people, $memberships, new MembershipLedger(), $this->authorizer(), $this->plainTransaction(), new RecordingOpenAssignments()))->activeMemberCount(AssociationDate::fromIso('2024-06-01')));
        self::assertSame('556012-3456', OrganizationNumber::parse('5560123456')->canonical());

        $this->expectException(InvalidArgumentException::class);
        OrganizationNumber::parse('5500123459');
    }

    public function test_personal_identity_must_match_an_explicit_birth_date_and_stays_out_of_ordinary_csv(): void
    {
        $today = AssociationDate::fromIso('2026-09-24');
        $coordination = PersonalIdentityNumber::parse('120477-0018', $today);

        self::assertSame('20120477-0018', $coordination->canonical());
        self::assertSame('2012-04-17', $coordination->civilBirthDate()->iso());

        [$service, $memberships, $people] = $this->people();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-id@example.test', 'Y-REM', 'youth', AssociationDate::fromIso('2024-01-01'), AssociationDate::fromIso('2012-04-17'), $today);
        $audit = new MemoryAuditLog();
        $identities = new MemoryPersonalIdentityRepository();
        $identity = new PersonalIdentityService($identities, $people, $this->authorizer([
            Capabilities::EDIT_PERSONAL_IDENTITY_NUMBERS,
            Capabilities::VIEW_PERSONAL_IDENTITY_NUMBERS,
        ]), $audit);
        $identity->store($personId, '20120417-0011', 'Association administration', 'Association policy', $today, $today, 4);

        $withoutBirth = $people->add(new Person(null, 'No', 'Date', 'nodate@example.test', PersonStatus::Known, null));
        $identity->store((int) $withoutBirth->id(), '20120417-0011', 'Association administration', '', $today, $today, 4);

        self::assertNull($people->find((int) $withoutBirth->id())?->birthDate());
        self::assertStringNotContainsString('20120417-0011', $audit->forObject('personal_identity', 1)[0]->action());

        $exchange = new MemberExchange($people, $memberships, new MembershipLedger(), $this->authorizer(), $this->plainTransaction(), new MemoryOrganizationRepository());

        self::assertStringNotContainsString('20120417-0011', $exchange->export());
        self::assertNull((new PersonalIdentityService($identities, $people, $this->authorizer([Capabilities::EDIT_MEMBERS]), $audit))->masked($personId));
        $people->save(new Person($personId, 'Lisa', 'Andersson', 'lisa-id@example.test', PersonStatus::Known, null, AssociationDate::fromIso('2012-04-18')));

        $this->expectException(InvalidArgumentException::class);
        $identity->store($personId, '20120417-0011', 'Association administration', '', $today, $today, 4);
    }

    public function test_guardian_relationships_do_not_duplicate_an_open_interval_and_approvals_follow_it(): void
    {
        [$people] = $this->stores();
        $child = $people->add(new Person(null, 'Lisa', 'Andersson', '', PersonStatus::Known, null));
        $anna = $people->add(new Person(null, 'Anna', 'Andersson', 'anna-guard@example.test', PersonStatus::Known, null));
        $guardians = new MemoryGuardianRepository();
        $service = new GuardianService($guardians, $people, $this->authorizer(), new MemoryAuditLog());
        $service->relate((int) $child->id(), (int) $anna->id(), 'parent', AssociationDate::fromIso('2020-01-01'));
        $first = $guardians->relationshipsForChild((int) $child->id())[0];
        $guardians->saveRelationship($first->ended(AssociationDate::fromIso('2022-12-31')));
        $service->relate((int) $child->id(), (int) $anna->id(), 'parent', AssociationDate::fromIso('2025-01-01'));

        self::assertCount(2, $guardians->relationshipsForChild((int) $child->id()));

        try {
            $service->relate((int) $child->id(), (int) $anna->id(), 'parent', AssociationDate::fromIso('2026-01-01'));
            self::fail('An overlapping guardian relationship should be rejected.');
        } catch (InvalidArgumentException) {
            self::assertCount(2, $guardians->relationshipsForChild((int) $child->id()));
        }

        try {
            $service->approve((int) $child->id(), (int) $anna->id(), 'Storage of personal identity number', '', new \DateTimeImmutable('2024-01-01 10:00:00'), 'Signed paper form', '2026-01', '', 4);
            self::fail('Approval needs a relationship that covers the approval date.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $guardians->approvalsForChild((int) $child->id()));
        }

        $approval = $service->approve((int) $child->id(), (int) $anna->id(), 'Storage of personal identity number', '', new \DateTimeImmutable('2026-09-24 10:00:00'), 'Signed paper form', '2026-01', '', 4);

        $this->expectException(InvalidArgumentException::class);
        $service->withdraw($approval, new \DateTimeImmutable('2026-09-01 10:00:00'), 4);
    }

    public function test_legacy_membership_copy_repairs_a_partial_row_and_keeps_separate_numbers(): void
    {
        $gateway = new MemoryLegacyGateway();
        $gateway->legacy = [
            $this->legacy('M-1', 4, 'ordinarie', 'active', '2018-03-01', null),
            $this->legacy('M-2', 4, 'ungdom', 'ended', '2010-01-01', '2012-12-31'),
        ];
        $gateway->participantFailures = 1;
        $importer = new LegacyMembershipImporter();

        try {
            $importer->copy($gateway);
            self::fail('A failed participant insert should abort that row.');
        } catch (RuntimeException) {
            self::assertSame([], $gateway->memberships);
        }

        $importer->copy($gateway);

        self::assertCount(2, $gateway->memberships);
        self::assertNotSame($gateway->memberships['M-1'], $gateway->memberships['M-2']);
        self::assertSame([4], $gateway->participants[$gateway->memberships['M-1']]);
        self::assertCount(1, $gateway->periods[$gateway->memberships['M-1']]);
        $membershipInserts = $gateway->inserts['membership'];
        $participantInserts = $gateway->inserts['participant'];
        $periodInserts = $gateway->inserts['period'];
        $importer->copy($gateway);

        self::assertSame($membershipInserts, $gateway->inserts['membership']);
        self::assertSame($participantInserts, $gateway->inserts['participant']);
        self::assertSame($periodInserts, $gateway->inserts['period']);

        $partial = new MemoryLegacyGateway();
        $partial->legacy = [$this->legacy('M-3', 8, 'family', 'active', '2024-01-01', null)];
        $partial->memberships['M-3'] = 30;
        $partial->participants[30] = [8];
        $importer->copy($partial);

        self::assertSame(0, $partial->inserts['membership']);
        self::assertSame(0, $partial->inserts['participant']);
        self::assertSame(1, $partial->inserts['period']);
        self::assertSame('2024-01-01', $partial->periods[30][0]['started_on']);
    }

    public function test_a_later_period_keeps_the_current_kind_as_its_historical_class(): void
    {
        [$service, $memberships] = $this->people();
        $service->register('Uno', 'Ung', '', 'Y-CLASS', 'youth', AssociationDate::fromIso('2018-01-01'), AssociationDate::fromIso('2012-04-17'), AssociationDate::fromIso('2026-09-24'));
        $account = $service->listPeople()[0]->account();
        $service->endMembership((int) $service->listPeople()[0]->membership()?->id(), AssociationDate::fromIso('2025-12-31'));
        $service->addPeriod((int) $account?->id(), AssociationDate::fromIso('2026-01-01'));
        $classes = array_map(
            static fn (MembershipPeriod $period): string => $period->historicalClass(),
            $memberships->periodsForMembership((int) $account?->id())
        );

        self::assertSame('youth', $account?->kind()->value);
        self::assertSame(['youth', 'youth'], $classes);
    }

    /**
     * @param list<string>|null $capabilities
     */
    private function authorizer(?array $capabilities = null): Authorizer
    {
        return new class ($capabilities) implements Authorizer {
            /** @param list<string>|null $capabilities */
            public function __construct(private ?array $capabilities)
            {
            }

            public function allows(string $capability): bool
            {
                return $this->capabilities === null || in_array($capability, $this->capabilities, true);
            }
        };
    }

    /**
     * @return array{0: PeopleService, 1: MemoryMembershipRepository, 2: MemoryPersonRepository}
     */
    private function people(): array
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $service = new PeopleService($people, $memberships, new MembershipLedger(), $this->authorizer(), $this->plainTransaction(), new RecordingOpenAssignments());

        return [$service, $memberships, $people];
    }

    /**
     * @return array{0: MemoryPersonRepository}
     */
    private function stores(): array
    {
        return [new MemoryPersonRepository()];
    }

    private function active(MemoryMembershipRepository $memberships, int $personId, string $on): bool
    {
        return MemberCoverage::isActiveMember($personId, AssociationDate::fromIso($on), $memberships->allParticipants(), $memberships->all());
    }

    private function plainTransaction(): Transaction
    {
        return new class implements Transaction {
            public function run(callable $callback): mixed
            {
                return $callback();
            }
        };
    }

    private function restoringTransaction(MemoryOrganizationRepository $organizations, MemoryMembershipRepository $memberships): Transaction
    {
        return new class ($organizations, $memberships) implements Transaction {
            public function __construct(
                private MemoryOrganizationRepository $organizations,
                private MemoryMembershipRepository $memberships,
            ) {
            }

            public function run(callable $callback): mixed
            {
                $organizations = $this->organizations->organizations;
                $accounts = $this->memberships->accounts;
                $participants = $this->memberships->participants;
                $periods = $this->memberships->periods;

                try {
                    return $callback();
                } catch (\Throwable $error) {
                    $this->organizations->organizations = $organizations;
                    $this->memberships->accounts = $accounts;
                    $this->memberships->participants = $participants;
                    $this->memberships->periods = $periods;

                    throw $error;
                }
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function legacy(string $number, int $personId, string $type, string $status, string $startedOn, ?string $endedOn): array
    {
        return [
            'membership_number' => $number,
            'person_id' => $personId,
            'membership_type' => $type,
            'status' => $status,
            'started_on' => $startedOn,
            'ended_on' => $endedOn,
        ];
    }
}

final class MemoryLegacyGateway implements LegacyMembershipGateway
{
    /** @var list<array<string, mixed>> */
    public array $legacy = [];

    /** @var array<string, int> */
    public array $memberships = [];

    /** @var array<int, list<int>> */
    public array $participants = [];

    /** @var array<int, list<array<string, string|null>>> */
    public array $periods = [];

    /** @var array<string, int> */
    public array $inserts = ['membership' => 0, 'participant' => 0, 'period' => 0];

    public int $participantFailures = 0;

    private int $nextId = 1;

    public function legacyRows(): array
    {
        return $this->legacy;
    }

    public function findMembershipId(string $number): ?int
    {
        return $this->memberships[$number] ?? null;
    }

    public function insertMembership(string $number, string $kind): int
    {
        unset($kind);
        $id = $this->nextId++;
        $this->memberships[$number] = $id;
        $this->inserts['membership']++;

        return $id;
    }

    public function hasParticipant(int $membershipId, int $personId): bool
    {
        return in_array($personId, $this->participants[$membershipId] ?? [], true);
    }

    public function insertParticipant(int $membershipId, int $personId, string $startedOn): void
    {
        unset($startedOn);

        if ($this->participantFailures > 0) {
            $this->participantFailures--;

            throw new RuntimeException('participant insert failed');
        }

        $this->participants[$membershipId][] = $personId;
        $this->inserts['participant']++;
    }

    public function hasPeriod(int $membershipId, string $status, string $startedOn, ?string $endedOn): bool
    {
        foreach ($this->periods[$membershipId] ?? [] as $period) {
            if ($period['status'] === $status && $period['started_on'] === $startedOn && $period['ended_on'] === $endedOn) {
                return true;
            }
        }

        return false;
    }

    public function insertPeriod(int $membershipId, string $status, string $startedOn, ?string $endedOn, string $historicalClass): void
    {
        unset($historicalClass);
        $this->periods[$membershipId][] = [
            'status' => $status,
            'started_on' => $startedOn,
            'ended_on' => $endedOn,
        ];
        $this->inserts['period']++;
    }

    public function transaction(callable $callback): void
    {
        $memberships = $this->memberships;
        $participants = $this->participants;
        $periods = $this->periods;
        $inserts = $this->inserts;
        $nextId = $this->nextId;

        try {
            $callback();
        } catch (\Throwable $error) {
            $this->memberships = $memberships;
            $this->participants = $participants;
            $this->periods = $periods;
            $this->inserts = $inserts;
            $this->nextId = $nextId;

            throw $error;
        }
    }
}
