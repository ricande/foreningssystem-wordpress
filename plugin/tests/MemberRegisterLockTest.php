<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Board\BoardService;
use Foreningssystem\Application\Board\EndOpenBoardAssignments;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\CompanyMemberships;
use Foreningssystem\Application\People\ContactChanged;
use Foreningssystem\Application\People\MemberCsvParser;
use Foreningssystem\Application\People\MemberExchange;
use Foreningssystem\Application\People\MemberRegisterBusy;
use Foreningssystem\Application\People\MemberRegisterImport;
use Foreningssystem\Application\People\MemberRegisterLock;
use Foreningssystem\Application\People\PeopleService;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardAssignmentLedger;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Board\BoardRuleException;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use PHPUnit\Framework\TestCase;

final class MemberRegisterLockTest extends TestCase
{
    public function test_a_busy_register_saves_nothing_and_starts_no_transaction(): void
    {
        [$service, $people, $memberships, $lock, $transaction] = $this->fixture();
        $personId = $this->ended($people, $memberships);
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $lock->busy = true;

        $calls = [
            'addPeriod' => fn () => $service->addPeriod($membershipId, AssociationDate::fromIso('2025-01-01')),
            'register' => fn () => $service->register('Bo', 'Lind', '', 'M-2', 'ordinary', AssociationDate::fromIso('2025-01-01')),
            'openForExistingPerson' => fn () => $service->openForExistingPerson($personId, 'M-3', 'ordinary', AssociationDate::fromIso('2025-01-01')),
            'endMembership' => fn () => $service->endMembership(1, AssociationDate::fromIso('2025-01-01')),
            'markDeceased' => fn () => $service->markDeceased($personId, AssociationDate::fromIso('2025-01-01')),
            'updateContact' => fn () => $service->updateContact($personId, 'Changed', 'Name', '', null, AssociationDate::fromIso('2025-01-01'), 1),
        ];

        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail($name . ' should report a busy register.');
            } catch (MemberRegisterBusy) {
            }
        }

        self::assertSame(0, $transaction->runs);
        self::assertCount(1, $memberships->all());
        self::assertCount(1, $memberships->allMemberships());
        self::assertCount(1, $people->all());
        self::assertSame('Ada', $people->find($personId)?->firstName());
        self::assertSame(PersonStatus::Known, $people->find($personId)?->status());
    }

    public function test_every_register_write_runs_its_transaction_inside_the_lock(): void
    {
        [$service, $people, $memberships, $lock, $transaction] = $this->fixture();
        $on = AssociationDate::fromIso('2025-01-01');
        $personId = $service->register('Ada', 'Lovelace', '', 'M-1', 'family', AssociationDate::fromIso('2024-01-01'));
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $other = $service->rememberPerson('Bo', 'Lind', '', null, $on);
        $service->addParticipant($membershipId, $other, ParticipantRole::Member, false, $on);
        $service->addPersonToMembership($membershipId, 'Cy', 'Lind', '', null, $on, ParticipantRole::Member, false, $on);
        $service->endParticipation($membershipId, $other, AssociationDate::fromIso('2025-02-01'));
        $service->endMembership(1, AssociationDate::fromIso('2025-03-01'));
        $service->addPeriod($membershipId, AssociationDate::fromIso('2025-04-01'));
        $service->updateContact($personId, 'Ada', 'King', '', null, $on, 1);
        $second = $service->register('Di', 'Berg', '', 'M-2', 'ordinary', AssociationDate::fromIso('2024-01-01'));
        $service->markDeceased($second, AssociationDate::fromIso('2025-05-01'));
        $service->openForExistingPerson($other, 'M-3', 'ordinary', AssociationDate::fromIso('2025-06-01'));

        self::assertSame(10, $lock->holds);
        self::assertSame(10, $transaction->runs);
        self::assertSame(0, $transaction->outsideLock);
        self::assertSame(0, $lock->depth);
    }

    public function test_the_overlap_check_reads_the_register_after_the_lock_is_taken(): void
    {
        [$service, $people, $memberships, $lock] = $this->fixture();
        $this->ended($people, $memberships);
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();

        // Another request held the register and saved a period first.
        $lock->before = static function () use ($memberships, $membershipId): void {
            $memberships->add(new MembershipPeriod(null, $membershipId, MembershipStatus::Active, AssociationDate::fromIso('2025-01-01'), null, 'ordinary'));
        };

        try {
            $service->addPeriod($membershipId, AssociationDate::fromIso('2025-01-01'));
            self::fail('The second period should overlap the one saved while waiting.');
        } catch (MembershipRuleException $error) {
            self::assertSame('Membership periods cannot overlap.', $error->getMessage());
        }

        self::assertCount(2, $memberships->periodsForMembership($membershipId));
    }

    public function test_a_membership_number_taken_while_waiting_is_refused(): void
    {
        [$service, , $memberships, $lock] = $this->fixture();
        $lock->before = static function () use ($memberships): void {
            $memberships->grant(99, 'M-9', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2025-01-01'), null);
        };

        $this->expectException(MembershipRuleException::class);
        $this->expectExceptionMessage('Membership number is already used.');

        try {
            $service->register('Bo', 'Lind', '', 'M-9', 'ordinary', AssociationDate::fromIso('2025-01-01'));
        } finally {
            // The person row is rolled back with the transaction. The fake does not
            // roll back, so the membership count is what shows the refusal.
            self::assertCount(1, $memberships->allMemberships());
        }
    }

    public function test_a_company_membership_waits_for_the_register(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $lock = new ScriptedRegisterLock();
        $transaction = new LockCheckingTransaction($lock);
        $companies = new CompanyMemberships(new MemoryOrganizationRepository(), $memberships, $people, $this->authorizer(), $transaction, $lock);

        $companies->register('Exempel AB', '', '', '', 'C-1', AssociationDate::fromIso('2025-01-01'), null);
        $lock->busy = true;

        try {
            $companies->register('Annat AB', '', '', '', 'C-2', AssociationDate::fromIso('2025-01-01'), null);
            self::fail('A busy register should refuse the company membership.');
        } catch (MemberRegisterBusy) {
        }

        self::assertSame(1, $transaction->runs);
        self::assertSame(0, $transaction->outsideLock);
        self::assertCount(1, $memberships->allMemberships());
    }

    public function test_a_member_file_row_that_finds_the_register_busy_is_reported_and_the_rest_is_imported(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $lock = new ScriptedRegisterLock();
        $lock->busyOnHold = [1];
        $transaction = new LockCheckingTransaction($lock);
        $exchange = new MemberExchange($people, $memberships, new MembershipLedger(), $this->authorizer(), $transaction, new MemoryOrganizationRepository(), $lock);

        $result = $exchange->import(<<<'CSV'
first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on
Ada;Lovelace;ada@example.test;known;M-1;ordinary;active;2024-01-01;
Bo;Lind;bo@example.test;known;M-2;ordinary;active;2024-01-01;
CSV);

        self::assertSame(1, $result->created());
        self::assertSame([
            'Line 2: Another change to the member register was being saved, so the row was not imported. Import the file again to add it.',
        ], $result->errors());
        self::assertNull($memberships->findMembershipByNumber('M-1'));
        self::assertNotNull($memberships->findMembershipByNumber('M-2'));
        self::assertSame(1, $transaction->runs);
        self::assertSame(0, $transaction->outsideLock);
    }

    public function test_a_spreadsheet_row_that_finds_the_register_busy_is_counted_as_failed(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $lock = new ScriptedRegisterLock();
        $lock->busyOnHold = [2];
        $transaction = new LockCheckingTransaction($lock);
        $import = new MemberRegisterImport($people, $memberships, new MembershipLedger(), $this->authorizer(), $transaction, $lock);
        $table = (new MemberCsvParser())->parse("first_name,last_name,email,membership_number,membership_type,started_on\n"
            . "Anna,Berg,anna@example.test,M-1,ordinary,2020-01-01\n"
            . "Bo,Lind,bo@example.test,M-2,ordinary,2020-01-01\n");
        $mapping = [0 => 'first_name', 1 => 'last_name', 2 => 'email', 3 => 'membership_number', 4 => 'membership_type', 5 => 'started_on'];

        $result = $import->commit($table, $mapping, AssociationDate::fromIso('2026-09-29'));

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->failed);
        self::assertSame('register_busy', $result->issues[0]->code);
        self::assertSame(3, $result->issues[0]->line);
        self::assertCount(1, $memberships->allMemberships());
        self::assertSame(0, $transaction->outsideLock);
    }

    public function test_a_contact_edit_from_a_stale_form_is_refused(): void
    {
        [$service, $people] = $this->fixture();
        $today = AssociationDate::fromIso('2025-01-01');
        $personId = $service->rememberPerson('Ada', 'Lovelace', 'ada@example.test', null, $today);
        $opened = $people->find($personId)?->contactVersion();
        self::assertIsString($opened);

        // Another officer saves first, from a form opened at the same time.
        $service->updateContact($personId, 'Ada', 'King', 'ada@example.test', null, $today, 1, $opened);

        try {
            $service->updateContact($personId, 'Augusta', 'Lovelace', 'ada@example.test', null, $today, 1, $opened);
            self::fail('The second save should not overwrite the first.');
        } catch (ContactChanged) {
        }

        $stored = $people->find($personId);
        self::assertSame('Ada', $stored?->firstName());
        self::assertSame('King', $stored?->lastName());

        $service->updateContact($personId, 'Augusta', 'King', 'ada@example.test', null, $today, 1, $stored?->contactVersion());
        self::assertSame('Augusta', $people->find($personId)?->firstName());
    }

    public function test_the_contact_version_follows_every_field_on_the_form(): void
    {
        $person = new Person(1, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null, AssociationDate::fromIso('1990-01-01'));
        $versions = [
            $person->contactVersion(),
            $person->withContact('Adah', 'Lovelace', 'ada@example.test', AssociationDate::fromIso('1990-01-01'))->contactVersion(),
            $person->withContact('Ada', 'King', 'ada@example.test', AssociationDate::fromIso('1990-01-01'))->contactVersion(),
            $person->withContact('Ada', 'Lovelace', 'ada@example.org', AssociationDate::fromIso('1990-01-01'))->contactVersion(),
            $person->withContact('Ada', 'Lovelace', 'ada@example.test', null)->contactVersion(),
        ];

        self::assertCount(5, array_unique($versions));
        self::assertSame($person->contactVersion(), $person->linkedToWordpressUser(7)->contactVersion());
    }

    public function test_a_busy_register_changes_no_board_assignment_and_starts_no_transaction(): void
    {
        [$board, $people, $memberships, $assignments, $lock, $transaction] = $this->boardFixture();
        $personId = (int) $people->add(new Person(null, 'Ada', 'Lovelace', '', PersonStatus::Known, null))->id();
        $memberships->grant($personId, 'M-1', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $current = $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2024-01-01'), null, '', ''));
        $scheduled = $assignments->add(new BoardAssignment(null, $personId, 2, AssociationDate::fromIso('2026-01-01'), null, '', ''));
        $lock->busy = true;

        $calls = [
            'place' => fn () => $board->place($personId, 2, AssociationDate::fromIso('2025-01-01'), AssociationDate::fromIso('2025-06-30'), '', ''),
            'end' => fn () => $board->end((int) $current->id(), AssociationDate::fromIso('2025-01-01')),
            'cancelScheduled' => fn () => $board->cancelScheduled((int) $scheduled->id(), AssociationDate::fromIso('2025-01-01')),
        ];

        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail($name . ' should report a busy register.');
            } catch (MemberRegisterBusy) {
            }
        }

        self::assertSame(0, $transaction->runs);
        self::assertCount(2, $assignments->all());
        self::assertNull($assignments->find((int) $current->id())?->endedOn());
    }

    public function test_every_board_write_runs_its_transaction_inside_the_lock(): void
    {
        [$board, $people, $memberships, $assignments, $lock, $transaction] = $this->boardFixture();
        $personId = (int) $people->add(new Person(null, 'Ada', 'Lovelace', '', PersonStatus::Known, null))->id();
        $memberships->grant($personId, 'M-1', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $today = AssociationDate::fromIso('2025-01-01');

        $board->place($personId, 1, AssociationDate::fromIso('2024-01-01'), null, '', '', $today);
        $board->place($personId, 2, AssociationDate::fromIso('2026-01-01'), null, '', '', $today);
        $current = $assignments->all()[0];
        $scheduled = $assignments->all()[1];
        $board->end((int) $current->id(), AssociationDate::fromIso('2025-06-30'));
        $board->cancelScheduled((int) $scheduled->id(), $today);

        self::assertSame(4, $lock->holds);
        self::assertSame(4, $transaction->runs);
        self::assertSame(0, $transaction->outsideLock);
        self::assertSame(0, $lock->depth);
    }

    public function test_an_assignment_is_refused_when_the_membership_ended_while_waiting(): void
    {
        [$board, $people, $memberships, $assignments, $lock] = $this->boardFixture();
        $personId = (int) $people->add(new Person(null, 'Ada', 'Lovelace', '', PersonStatus::Known, null))->id();
        $period = $memberships->grant($personId, 'M-1', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $other = new PeopleService(
            $people,
            $memberships,
            new MembershipLedger(),
            $this->authorizer(),
            new LockCheckingTransaction(new ScriptedRegisterLock()),
            new EndOpenBoardAssignments($assignments, new BoardAssignmentLedger()),
            new UnheldRegisterLock(),
            new MemoryAuditLog()
        );

        // Another officer held the register and ended the membership first.
        $lock->before = static function () use ($other, $period): void {
            $other->endMembership((int) $period->id(), AssociationDate::fromIso('2025-06-30'));
        };

        try {
            $board->place($personId, 1, AssociationDate::fromIso('2025-01-01'), null, '', '');
            self::fail('An open assignment past the end of the membership should be refused.');
        } catch (BoardRuleException $error) {
            self::assertSame('The membership does not cover this assignment.', $error->getMessage());
        }

        self::assertSame([], $assignments->all());
    }

    /**
     * @return array{0: BoardService, 1: MemoryPersonRepository, 2: MemoryMembershipRepository, 3: MemoryBoardAssignmentRepository, 4: ScriptedRegisterLock, 5: LockCheckingTransaction}
     */
    private function boardFixture(): array
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $roles = new MemoryBoardRoleRepository();
        $roles->add(new BoardRole(null, 'chair', 'Chair', false, 10));
        $roles->add(new BoardRole(null, 'treasurer', 'Treasurer', false, 20));
        $assignments = new MemoryBoardAssignmentRepository();
        $lock = new ScriptedRegisterLock();
        $transaction = new LockCheckingTransaction($lock);
        $board = new BoardService($people, $memberships, $roles, $assignments, new BoardAssignmentLedger(), $this->authorizer(), $transaction, $lock);

        return [$board, $people, $memberships, $assignments, $lock, $transaction];
    }

    /**
     * @return array{0: PeopleService, 1: MemoryPersonRepository, 2: MemoryMembershipRepository, 3: ScriptedRegisterLock, 4: LockCheckingTransaction}
     */
    private function fixture(): array
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $lock = new ScriptedRegisterLock();
        $transaction = new LockCheckingTransaction($lock);
        $service = new PeopleService($people, $memberships, new MembershipLedger(), $this->authorizer(), $transaction, new RecordingOpenAssignments(), $lock, new MemoryAuditLog());

        return [$service, $people, $memberships, $lock, $transaction];
    }

    private function ended(MemoryPersonRepository $people, MemoryMembershipRepository $memberships): int
    {
        $personId = (int) $people->add(new Person(null, 'Ada', 'Lovelace', '', PersonStatus::Known, null))->id();
        $memberships->grant($personId, 'M-1', 'ordinary', MembershipStatus::Ended, AssociationDate::fromIso('2024-01-01'), AssociationDate::fromIso('2024-12-31'), MembershipKind::Ordinary);

        return $personId;
    }

    private function authorizer(): Authorizer
    {
        return new class implements Authorizer {
            public function allows(string $capability): bool
            {
                return true;
            }
        };
    }
}

/**
 * A register lock for tests that need no concurrency: it runs the work at once.
 */
final class UnheldRegisterLock implements MemberRegisterLock
{
    public function hold(callable $work): mixed
    {
        return $work();
    }
}

final class ScriptedRegisterLock implements MemberRegisterLock
{
    public bool $busy = false;

    /** @var list<int> 1-based hold calls that find the register busy. */
    public array $busyOnHold = [];

    /** @var (callable(): void)|null runs after the lock is taken, before the work. */
    public $before = null;

    public int $holds = 0;

    public int $depth = 0;

    private int $calls = 0;

    public function hold(callable $work): mixed
    {
        $this->calls++;

        if ($this->busy || in_array($this->calls, $this->busyOnHold, true)) {
            throw new MemberRegisterBusy('Another change to the member register holds it.');
        }

        $this->holds++;
        $this->depth++;

        try {
            if ($this->before !== null) {
                ($this->before)();
                $this->before = null;
            }

            return $work();
        } finally {
            $this->depth--;
        }
    }
}

final class LockCheckingTransaction implements Transaction
{
    public int $runs = 0;

    public int $outsideLock = 0;

    public function __construct(private readonly ScriptedRegisterLock $lock)
    {
    }

    public function run(callable $callback): mixed
    {
        $this->runs++;

        if ($this->lock->depth < 1) {
            $this->outsideLock++;
        }

        return $callback();
    }
}
