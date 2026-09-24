<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Board\BoardService;
use Foreningssystem\Application\Board\EndOpenBoardAssignments;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\PeopleService;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardAssignmentLedger;
use Foreningssystem\Domain\Board\BoardAssignmentRepository;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Board\BoardRoleRepository;
use Foreningssystem\Domain\Board\BoardRuleException;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use Foreningssystem\Infrastructure\Persistence\BoardSchemaMigration;
use PHPUnit\Framework\TestCase;

final class BoardServiceTest extends TestCase
{
    public function test_assignment_follows_membership_dates_and_keeps_replaced_rows(): void
    {
        [$service, $people, $memberships, $roles, $assignments] = $this->world(true);
        $ada = $this->person($people, 'Ada', 'Lovelace', 'ada@example.test');
        $grace = $this->person($people, 'Grace', 'Hopper', 'grace@example.test');
        $memberships->add($this->membership($ada, 'M-1', MembershipStatus::Active, '2024-01-01', null));
        $memberships->add($this->membership($grace, 'M-2', MembershipStatus::Active, '2024-01-01', null));
        $chair = $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10));
        $auditor = $roles->add(new BoardRole(null, 'auditor', 'Revisor', true, 50));

        try {
            $service->place($ada, (int) $chair->id(), AssociationDate::fromIso('2023-01-01'), null, '', '');
            self::fail('An assignment before the membership should be rejected.');
        } catch (BoardRuleException) {
            self::assertSame([], $assignments->all());
        }

        self::assertSame('saved', $service->place($ada, (int) $chair->id(), AssociationDate::fromIso('2024-01-01'), null, 'ordf@example.test', '2024'));
        self::assertSame('replaced', $service->place($grace, (int) $chair->id(), AssociationDate::fromIso('2024-03-01'), null, '', ''));

        $adaChair = $this->assignmentFor($assignments, $ada, (int) $chair->id());
        $graceChair = $this->assignmentFor($assignments, $grace, (int) $chair->id());
        self::assertSame('2024-02-29', $adaChair->endedOn()?->iso());
        self::assertSame('ordf@example.test', $adaChair->publicContact());
        self::assertNotSame('ada@example.test', $adaChair->publicContact());
        self::assertNull($graceChair->endedOn());

        $service->place($ada, (int) $auditor->id(), AssociationDate::fromIso('2024-01-01'), null, '', '');
        $service->place($grace, (int) $auditor->id(), AssociationDate::fromIso('2024-01-01'), null, '', '');
        self::assertCount(4, $assignments->all());

        $current = array_values(array_filter(
            $service->history(AssociationDate::fromIso('2024-06-01')),
            static fn ($post): bool => $post->current()
        ));
        self::assertCount(3, $current);

        foreach ($service->history(AssociationDate::fromIso('2024-06-01')) as $post) {
            self::assertStringNotContainsString('@', $post->personName());
        }
    }

    public function test_only_active_or_ended_membership_dates_cover_an_assignment(): void
    {
        [$service, $people, $memberships, $roles] = $this->world(true);
        $personId = $this->person($people, 'Ada', 'Lovelace', 'ada@example.test');
        $roleId = (int) $roles->add(new BoardRole(null, 'secretary', 'Sekreterare', false, 30))->id();
        $memberships->add($this->membership($personId, 'M-1', MembershipStatus::Dormant, '2024-01-01', null));

        try {
            $service->place($personId, $roleId, AssociationDate::fromIso('2024-02-01'), null, '', '');
            self::fail('A dormant membership should not cover a board assignment.');
        } catch (BoardRuleException) {
        }

        $existing = $memberships->find(1);
        self::assertInstanceOf(MembershipPeriod::class, $existing);
        $memberships->save(new MembershipPeriod(
            $existing->id(),
            $existing->personId(),
            $existing->number(),
            $existing->type(),
            MembershipStatus::Ended,
            $existing->startedOn(),
            AssociationDate::fromIso('2024-06-01')
        ));
        $service->place($personId, $roleId, AssociationDate::fromIso('2024-01-01'), AssociationDate::fromIso('2024-06-01'), '', '');

        try {
            $service->place($personId, $roleId, AssociationDate::fromIso('2024-01-01'), null, '', '');
            self::fail('An ended membership should not cover an open assignment.');
        } catch (BoardRuleException) {
        }
    }

    public function test_a_deceased_person_is_not_on_the_current_board(): void
    {
        [$service, $people, $memberships, $roles] = $this->world(true);
        $person = $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null));
        $personId = (int) $person->id();
        $memberships->add($this->membership($personId, 'M-1', MembershipStatus::Active, '2024-01-01', null));
        $roleId = (int) $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10))->id();
        $service->place($personId, $roleId, AssociationDate::fromIso('2024-01-01'), null, '', '');
        $people->save($person->markedDeceased());

        self::assertFalse($service->history(AssociationDate::fromIso('2024-06-01'))[0]->current());

        try {
            $service->place($personId, $roleId, AssociationDate::fromIso('2024-07-01'), null, '', '');
            self::fail('A deceased person should not receive an open assignment.');
        } catch (BoardRuleException) {
        }
    }

    public function test_editing_the_board_requires_manage_board(): void
    {
        [$service] = $this->world(false);

        $this->expectException(NotAllowed::class);
        $service->place(1, 1, AssociationDate::fromIso('2024-01-01'), null, '', '');
    }

    public function test_ending_a_membership_ends_open_assignments_on_the_same_day(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $assignments = new MemoryBoardAssignmentRepository();
        $personId = (int) $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null))->id();
        $period = $memberships->add($this->membership($personId, 'M-1', MembershipStatus::Active, '2024-01-01', null));
        $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2024-01-01'), null, 'ordf@example.test', ''));
        $assignments->add(new BoardAssignment(null, $personId, 2, AssociationDate::fromIso('2023-01-01'), AssociationDate::fromIso('2023-12-31'), '', ''));
        $service = new PeopleService($people, $memberships, new MembershipLedger(), $this->authorizer(true), $this->transaction(), new EndOpenBoardAssignments($assignments, new BoardAssignmentLedger()));

        $service->endMembership((int) $period->id(), AssociationDate::fromIso('2024-06-01'));

        self::assertSame('2024-06-01', $this->assignmentFor($assignments, $personId, 1)->endedOn()?->iso());
        self::assertSame('2023-12-31', $this->assignmentFor($assignments, $personId, 2)->endedOn()?->iso());
        self::assertSame('ordf@example.test', $this->assignmentFor($assignments, $personId, 1)->publicContact());
    }

    public function test_schema_migration_creates_board_tables_and_suggested_roles(): void
    {
        $migration = new BoardSchemaMigration('wp_', 'DEFAULT CHARSET utf8mb4');
        $sql = $migration->statements();
        $multiple = [];

        foreach ($migration->suggestedRoles() as $role) {
            $multiple[$role['slug']] = $role['allows_multiple'];
        }

        self::assertSame(3, $migration->version());
        self::assertStringContainsString('CREATE TABLE wp_assoc_board_role', $sql);
        self::assertStringContainsString('CREATE TABLE wp_assoc_board_assignment', $sql);
        self::assertStringNotContainsString('wp_users', $sql);
        self::assertSame(0, $multiple['chair']);
        self::assertSame(1, $multiple['auditor']);
        self::assertSame(1, $multiple['election_committee']);
    }

    /**
     * @return array{0: BoardService, 1: MemoryPersonRepository, 2: MemoryMembershipRepository, 3: MemoryBoardRoleRepository, 4: MemoryBoardAssignmentRepository}
     */
    private function world(bool $allowed): array
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $roles = new MemoryBoardRoleRepository();
        $assignments = new MemoryBoardAssignmentRepository();
        $service = new BoardService($people, $memberships, $roles, $assignments, new BoardAssignmentLedger(), $this->authorizer($allowed), $this->transaction());

        return [$service, $people, $memberships, $roles, $assignments];
    }

    private function person(MemoryPersonRepository $people, string $firstName, string $lastName, string $email): int
    {
        return (int) $people->add(new Person(null, $firstName, $lastName, $email, PersonStatus::Known, null))->id();
    }

    private function membership(int $personId, string $number, MembershipStatus $status, string $startedOn, ?string $endedOn): MembershipPeriod
    {
        return new MembershipPeriod(
            null,
            $personId,
            $number,
            'ordinarie',
            $status,
            AssociationDate::fromIso($startedOn),
            $endedOn === null ? null : AssociationDate::fromIso($endedOn)
        );
    }

    private function assignmentFor(MemoryBoardAssignmentRepository $assignments, int $personId, int $roleId): BoardAssignment
    {
        foreach ($assignments->all() as $assignment) {
            if ($assignment->personId() === $personId && $assignment->roleId() === $roleId) {
                return $assignment;
            }
        }

        self::fail('Assignment was not found.');
    }

    private function authorizer(bool $allowed): Authorizer
    {
        return new class ($allowed) implements Authorizer {
            public function __construct(private bool $allowed)
            {
            }

            public function allows(string $capability): bool
            {
                if (! $this->allowed) {
                    return false;
                }

                return in_array($capability, [Capabilities::MANAGE_BOARD, Capabilities::VIEW_MEMBERS, Capabilities::EDIT_MEMBERS], true);
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
}

final class MemoryBoardRoleRepository implements BoardRoleRepository
{
    /** @var array<int, BoardRole> */
    public array $roles = [];

    private int $nextId = 1;

    public function add(BoardRole $role): BoardRole
    {
        $saved = $role->withId($this->nextId);
        $this->roles[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function find(int $id): ?BoardRole
    {
        return $this->roles[$id] ?? null;
    }

    public function findBySlug(string $slug): ?BoardRole
    {
        foreach ($this->roles as $role) {
            if ($role->slug() === $slug) {
                return $role;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_values($this->roles);
    }
}

final class MemoryBoardAssignmentRepository implements BoardAssignmentRepository
{
    /** @var array<int, BoardAssignment> */
    public array $assignments = [];

    private int $nextId = 1;

    public function add(BoardAssignment $assignment): BoardAssignment
    {
        $saved = $assignment->withId($this->nextId);
        $this->assignments[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function save(BoardAssignment $assignment): void
    {
        $id = $assignment->id();

        if ($id === null || ! isset($this->assignments[$id])) {
            throw new \RuntimeException('Assignment was not found.');
        }

        $this->assignments[$id] = $assignment;
    }

    public function find(int $id): ?BoardAssignment
    {
        return $this->assignments[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->assignments);
    }
}
