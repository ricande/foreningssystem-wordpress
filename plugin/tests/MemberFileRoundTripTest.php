<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\MemberExchange;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\Membership;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipParticipant;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Domain\Organization\Organization;
use Foreningssystem\Domain\Organization\OrganizationNumber;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Export, then import into an empty register.
 * The structured file must give back the same people, periods, participants and statuses.
 */
final class MemberFileRoundTripTest extends TestCase
{
    /**
     * @return iterable<string, array{0: callable(MemberFileRegister): void}>
     */
    public static function registers(): iterable
    {
        yield 'returning member' => [static function (MemberFileRegister $register): void {
            $ada = $register->person('Ada', 'Lovelace', 'ada-trip@example.test');
            $membership = $register->membership('M-1', MembershipKind::Ordinary);
            $register->period($membership, MembershipStatus::Ended, '2019-01-01', '2020-12-31', 'ordinarie');
            $register->period($membership, MembershipStatus::Active, '2023-01-01', null, 'ordinary');
            $register->participant($membership, $ada, true, '2019-01-01', '2020-12-31');
            $register->participant($membership, $ada, false, '2023-01-01', null);
        }];

        yield 'family with two members' => [static function (MemberFileRegister $register): void {
            $bo = $register->person('Bo', 'Familj', 'bo-trip@example.test');
            $cia = $register->person('Cia', 'Familj', '', '2015-04-02');
            $membership = $register->membership('F-1', MembershipKind::Family);
            $register->period($membership, MembershipStatus::Active, '2022-01-01', null, 'family');
            $register->participant($membership, $bo, true, '2022-01-01', null);
            $register->participant($membership, $cia, false, '2023-05-01', null);
        }];

        yield 'deceased person' => [static function (MemberFileRegister $register): void {
            $dag = $register->person('Dag', 'Avliden', 'dag-trip@example.test', null, PersonStatus::Deceased);
            $membership = $register->membership('M-2', MembershipKind::Ordinary);
            $register->period($membership, MembershipStatus::Ended, '2018-01-01', '2024-03-01', 'ordinary');
            $register->participant($membership, $dag, true, '2018-01-01', '2024-03-01');
        }];

        yield 'Swedish letters, semicolons and quotes' => [static function (MemberFileRegister $register): void {
            $asa = $register->person('Åsa;Maria', 'Öberg "Ö"', 'asa-trip@example.test');
            $ake = $register->person('Åke', 'Ärlig\\', '');
            $organization = $register->organization('Ärlig Förening Å;Ö AB', '556012-3456', "Storgatan 1\n123 45 Åby;\"C/O\"");
            $own = $register->membership('M-3', MembershipKind::Ordinary);
            $register->period($own, MembershipStatus::Active, '2024-01-01', null, 'Årsavgift; full');
            $register->participant($own, $asa, true, '2024-01-01', null);
            $company = $register->membership('C-1', MembershipKind::Company, $organization);
            $register->period($company, MembershipStatus::Active, '2024-01-01', null, 'company');
            $register->participant($company, $asa, true, '2024-01-01', null, ParticipantRole::Contact);
            $register->participant($company, $ake, false, '2024-02-01', null, ParticipantRole::Contact);
        }];

        yield 'formula-like names' => [static function (MemberFileRegister $register): void {
            $names = [['=SUM(A1)', '+46'], ['-Lina', '@home'], ["'=Kvot", "'Apostrof"], ["\tTabb", '=1+1']];

            foreach ($names as $index => [$first, $last]) {
                $person = $register->person($first, $last, 'formula-' . $index . '@example.test');
                $membership = $register->membership('=M-' . $index, MembershipKind::Ordinary);
                $register->period($membership, MembershipStatus::Active, '2024-01-01', null, '+ordinary');
                $register->participant($membership, $person, true, '2024-01-01', null);
            }
        }];
    }

    /**
     * @param callable(MemberFileRegister): void $build
     */
    #[DataProvider('registers')]
    public function test_the_structured_file_round_trips_into_an_empty_register(callable $build): void
    {
        $source = new MemberFileRegister();
        $build($source);
        $exported = $source->exchange()->exportStructure();

        $target = new MemberFileRegister();
        $result = $target->exchange()->import($exported);

        self::assertSame([], $result->errors());
        self::assertSame($source->snapshot(), $target->snapshot());
        self::assertSame(0, $target->exchange()->import($exported)->created());
        self::assertSame($source->snapshot(), $target->snapshot());
    }

    public function test_formula_cells_are_guarded_in_the_structured_file(): void
    {
        $register = new MemberFileRegister();
        $build = iterator_to_array(self::registers())['formula-like names'][0];
        $build($register);
        $exported = $register->exchange()->exportStructure();

        foreach (explode("\n", $exported) as $line) {
            foreach (str_getcsv($line, ';', '"', '\\') as $cell) {
                self::assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', (string) $cell, $line);
            }
        }

        self::assertStringContainsString("''=Kvot", $exported);
    }

    public function test_a_structured_file_with_swedish_letters_reports_the_physical_line(): void
    {
        $register = new MemberFileRegister();
        $result = $register->exchange()->import(<<<'CSV'
# foreningsplugin-members 2
organization;556012-3456;"Ärlig Förening";;"Storgatan 1
Åby"
membership;M-1;ordinary;
period;M-1;active;2024-01-01;;ordinarie
participant;M-1;Åsa;Ödmark;asa-line@example.test;;member;1;2024-01-01;
participant;M-1;Ärla;;;;member;0;2024-01-01;
CSV);

        self::assertSame(['Line 7: A person needs a first and last name.'], $result->errors());
        self::assertSame('Åsa', $register->people->all()[0]->firstName());
        self::assertSame('Ödmark', $register->people->all()[0]->lastName());
        self::assertSame("Storgatan 1\nÅby", $register->organizations->all()[0]->postalAddress());
        self::assertSame('Ärlig Förening', $register->organizations->all()[0]->name());
    }

    public function test_a_participant_row_without_the_status_column_is_a_known_person(): void
    {
        $register = new MemberFileRegister();
        $result = $register->exchange()->import(<<<'CSV'
# foreningsplugin-members 2
membership;M-1;ordinary;
period;M-1;ended;2020-01-01;2020-12-31;ordinary
participant;M-1;Ada;Öberg "Ö";ada-old@example.test;;member;1;2020-01-01;2020-12-31
CSV);

        self::assertSame([], $result->errors());
        self::assertSame(PersonStatus::Known, $register->people->all()[0]->status());
        self::assertSame('Öberg "Ö"', $register->people->all()[0]->lastName());
    }

    public function test_an_unknown_or_impossible_person_status_is_rejected(): void
    {
        $register = new MemberFileRegister();
        $result = $register->exchange()->import(<<<'CSV'
# foreningsplugin-members 2
membership;M-1;ordinary;
period;M-1;active;2020-01-01;;ordinary
participant;M-1;Ada;Lovelace;ada-status@example.test;;member;1;2020-01-01;;sleeping
participant;M-1;Ada;Lovelace;ada-status@example.test;;member;1;2020-01-01;;deceased
CSV);

        self::assertSame([
            'Line 4: Unknown person status.',
            'Line 5: A deceased person cannot have an open membership.',
        ], $result->errors());
        self::assertSame([], $register->people->all());
        self::assertSame([], $register->memberships->allParticipants());
    }

    public function test_a_file_does_not_mark_a_stored_person_deceased(): void
    {
        $register = new MemberFileRegister();
        $register->person('Ada', 'Lovelace', 'ada-stored@example.test');
        $result = $register->exchange()->import(<<<'CSV'
# foreningsplugin-members 2
membership;M-1;ordinary;
period;M-1;ended;2020-01-01;2020-12-31;ordinary
participant;M-1;Ada;Lovelace;ada-stored@example.test;;member;1;2020-01-01;2020-12-31;deceased
CSV);

        self::assertSame([], $result->errors());
        self::assertCount(1, $register->people->all());
        self::assertSame(PersonStatus::Known, $register->people->all()[0]->status());
    }

    public function test_a_row_that_fails_to_save_is_reported_on_its_line_and_rolled_back(): void
    {
        $register = new MemberFileRegister(true);
        $register->memberships->failNext = 'participant';
        $result = $register->exchange()->import(<<<'CSV'
# foreningsplugin-members 2
membership;M-1;family;
period;M-1;active;2024-01-01;;family
participant;M-1;Bea;Sen;bea-fail@example.test;;member;1;2024-01-01;
participant;M-1;Cid;Sen;cid-fail@example.test;;member;0;2024-01-01;
CSV);

        self::assertSame(['Line 4: The row could not be saved. No part of it was kept.'], $result->errors());
        self::assertCount(1, $register->people->all());
        self::assertSame('Cid', $register->people->all()[0]->firstName());
        self::assertCount(1, $register->memberships->allParticipants());

        $plain = new MemberFileRegister(true);
        $plain->memberships->failNext = 'period';
        $rows = $plain->exchange()->import(<<<'CSV'
first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on
Bea;Sen;bea-fail@example.test;known;M-1;ordinary;active;2024-01-01;
Cid;Sen;cid-fail@example.test;known;M-2;ordinary;active;2024-01-01;
CSV);

        self::assertSame(['Line 2: The row could not be saved. No part of it was kept.'], $rows->errors());
        self::assertSame(1, $rows->created());
        self::assertCount(1, $plain->people->all());
        self::assertNull($plain->memberships->findMembershipByNumber('M-1'));
    }

    public function test_the_plain_file_round_trips_single_period_people_with_hard_names(): void
    {
        $source = new MemberFileRegister();
        $asa = $source->person('Åsa;Maria', 'Öberg "Ö"', 'asa-plain@example.test');
        $formula = $source->person('=SUM(A1)', "'=Kvot", '');
        $dag = $source->person('Dag', 'Avliden', 'dag-plain@example.test', null, PersonStatus::Deceased);

        foreach ([['M-1', $asa, MembershipStatus::Active, null], ['M-2', $formula, MembershipStatus::Active, null], ['M-3', $dag, MembershipStatus::Ended, '2024-03-01']] as [$number, $person, $status, $end]) {
            $membership = $source->membership($number, MembershipKind::Ordinary);
            $source->period($membership, $status, '2020-01-01', $end, 'ordinary');
            // The plain file has no participation interval; import starts it with the period and leaves it open.
            $source->participant($membership, $person, true, '2020-01-01', null);
        }

        $target = new MemberFileRegister();
        $result = $target->exchange()->import($source->exchange()->export());

        self::assertSame([], $result->errors());
        self::assertSame(3, $result->created());
        self::assertSame($source->snapshot(), $target->snapshot());
    }

    public function test_the_plain_file_reports_later_periods_and_family_members_as_not_imported(): void
    {
        $source = new MemberFileRegister();
        $build = iterator_to_array(self::registers());
        $build['returning member'][0]($source);
        $build['family with two members'][0]($source);
        $exported = $source->exchange()->export();

        $target = new MemberFileRegister();
        $result = $target->exchange()->import($exported);
        $again = $target->exchange()->import($exported);

        self::assertSame(2, $result->created());
        self::assertSame(0, $result->skipped());
        self::assertCount(2, $result->errors());
        self::assertStringStartsWith('Line 3: Not imported. Membership M-1 was created from an earlier line.', $result->errors()[0]);
        self::assertStringStartsWith('Line 5: Not imported. Membership F-1 was created from an earlier line.', $result->errors()[1]);
        self::assertStringContainsString('Use the structured member file', $result->errors()[0]);
        self::assertSame(0, $again->created());
        self::assertSame(4, $again->skipped());
        self::assertSame([], $again->errors());
    }
}

/**
 * An in-memory register built through the repositories, not through the import under test.
 */
final class MemberFileRegister
{
    public MemoryPersonRepository $people;

    public MemoryMembershipRepository $memberships;

    public MemoryOrganizationRepository $organizations;

    public function __construct(private bool $restoring = false)
    {
        $this->people = new MemoryPersonRepository();
        $this->memberships = new MemoryMembershipRepository();
        $this->organizations = new MemoryOrganizationRepository();
    }

    public function exchange(): MemberExchange
    {
        return new MemberExchange(
            $this->people,
            $this->memberships,
            new MembershipLedger(),
            new class implements Authorizer {
                public function allows(string $capability): bool
                {
                    return in_array($capability, [Capabilities::EDIT_MEMBERS, Capabilities::EXPORT_MEMBERS], true);
                }
            },
            $this->transaction(),
            $this->organizations,
            new UnheldRegisterLock()
        );
    }

    public function person(string $first, string $last, string $email, ?string $birth = null, PersonStatus $status = PersonStatus::Known): int
    {
        $birthDate = $birth === null ? null : AssociationDate::fromIso($birth);

        return (int) $this->people->add(new Person(null, $first, $last, $email, $status, null, $birthDate))->id();
    }

    public function organization(string $name, string $number, string $address): int
    {
        return (int) $this->organizations->add(new Organization(null, $name, OrganizationNumber::parse($number), '', $address))->id();
    }

    public function membership(string $number, MembershipKind $kind, ?int $organizationId = null): int
    {
        return (int) $this->memberships->addMembership(new Membership(null, $number, $kind, $organizationId))->id();
    }

    public function period(int $membershipId, MembershipStatus $status, string $start, ?string $end, string $class): void
    {
        $this->memberships->add(new MembershipPeriod(
            null,
            $membershipId,
            $status,
            AssociationDate::fromIso($start),
            $end === null ? null : AssociationDate::fromIso($end),
            $class
        ));
    }

    public function participant(int $membershipId, int $personId, bool $primary, string $start, ?string $end, ParticipantRole $role = ParticipantRole::Member): void
    {
        $this->memberships->addParticipant(new MembershipParticipant(
            null,
            $membershipId,
            $personId,
            $role,
            $primary,
            AssociationDate::fromIso($start),
            $end === null ? null : AssociationDate::fromIso($end)
        ));
    }

    /**
     * Everything the file carries, without database ids.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $person = function (int $id): array {
            $found = $this->people->find($id);

            return $found === null ? [] : [
                $found->firstName(),
                $found->lastName(),
                $found->email(),
                $found->birthDate()?->iso(),
                $found->status()->value,
            ];
        };
        $people = array_map(static fn (Person $found): array => $person((int) $found->id()), $this->people->all());
        sort($people);
        $organizations = array_map(static fn (Organization $organization): array => [
            $organization->name(),
            $organization->number()?->canonical(),
            $organization->email(),
            $organization->postalAddress(),
        ], $this->organizations->all());
        $memberships = [];

        foreach ($this->memberships->allMemberships() as $membership) {
            $id = (int) $membership->id();
            $organization = $membership->organizationId() === null ? null : $this->organizations->find($membership->organizationId());
            $periods = array_map(static fn (MembershipPeriod $period): array => [
                $period->status()->value,
                $period->startedOn()->iso(),
                $period->endedOn()?->iso(),
                $period->historicalClass(),
            ], $this->memberships->periodsForMembership($id));
            $participants = array_map(static fn (MembershipParticipant $participant): array => [
                $person($participant->personId()),
                $participant->role()->value,
                $participant->isPrimary(),
                $participant->startedOn()->iso(),
                $participant->endedOn()?->iso(),
            ], $this->memberships->participantsForMembership($id));
            sort($periods);
            sort($participants);
            $memberships[$membership->number()] = [
                $membership->kind()->value,
                $organization?->number()?->canonical(),
                $periods,
                $participants,
            ];
        }

        ksort($memberships);

        return ['people' => $people, 'organizations' => $organizations, 'memberships' => $memberships];
    }

    private function transaction(): Transaction
    {
        if (! $this->restoring) {
            return new class implements Transaction {
                public function run(callable $callback): mixed
                {
                    return $callback();
                }
            };
        }

        return new class ($this->people, $this->memberships) implements Transaction {
            public function __construct(
                private MemoryPersonRepository $people,
                private MemoryMembershipRepository $memberships,
            ) {
            }

            public function run(callable $callback): mixed
            {
                $people = $this->people->people;
                $accounts = $this->memberships->accounts;
                $participants = $this->memberships->participants;
                $periods = $this->memberships->periods;

                try {
                    return $callback();
                } catch (\Throwable $error) {
                    $this->people->people = $people;
                    $this->memberships->accounts = $accounts;
                    $this->memberships->participants = $participants;
                    $this->memberships->periods = $periods;

                    throw $error;
                }
            }
        };
    }
}
