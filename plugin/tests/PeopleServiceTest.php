<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\OpenBoardAssignments;
use Foreningssystem\Application\People\PeopleService;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;
use Foreningssystem\Domain\Person\PersonStatus;
use Foreningssystem\Infrastructure\Persistence\MembershipSchemaMigration;
use PHPUnit\Framework\TestCase;

final class PeopleServiceTest extends TestCase
{
    public function test_register_end_and_death_follow_the_membership_rules(): void
    {
        $closer = new RecordingOpenAssignments();
        $service = $this->service(true, $closer);
        $personId = $service->register('Ada', 'Lovelace', 'ada@example.test', 'M-1', 'ordinarie', AssociationDate::fromIso('2024-01-01'));

        self::assertSame(1, $service->activeMemberCount());

        try {
            $service->register('Grace', 'Hopper', 'grace@example.test', 'M-1', 'ordinarie', AssociationDate::fromIso('2024-02-01'));
            self::fail('A duplicate membership number should be rejected.');
        } catch (MembershipRuleException) {
            self::assertSame(1, $service->activeMemberCount());
        }

        $membershipId = $service->listPeople()[0]->membership()?->id();
        self::assertSame(1, $membershipId);
        $service->endMembership((int) $membershipId, AssociationDate::fromIso('2024-06-01'));

        $ended = $service->listPeople()[0];
        self::assertSame($personId, $ended->person()->id());
        self::assertSame(PersonStatus::Known, $ended->person()->status());
        self::assertSame(MembershipStatus::Ended, $ended->membership()?->status());
        self::assertSame(0, $service->activeMemberCount());

        $secondId = $service->register('Grace', 'Hopper', '', 'M-2', 'ordinarie', AssociationDate::fromIso('2023-01-01'));
        $service->markDeceased($secondId, AssociationDate::fromIso('2024-09-24'));
        $deceased = null;

        foreach ($service->listPeople() as $record) {
            if ($record->person()->id() === $secondId) {
                $deceased = $record;
            }
        }

        self::assertNotNull($deceased);
        self::assertSame(PersonStatus::Deceased, $deceased->person()->status());
        self::assertSame(MembershipStatus::Ended, $deceased->membership()?->status());
        self::assertSame('2024-09-24', $deceased->membership()?->endedOn()?->iso());
        self::assertSame([
            [$personId, '2024-06-01'],
            [$secondId, '2024-09-24'],
        ], $closer->calls);
    }

    public function test_viewing_and_editing_require_capabilities(): void
    {
        $service = $this->service(false);

        $this->expectException(NotAllowed::class);
        $service->register('Ada', 'Lovelace', '', 'M-1', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
    }

    public function test_schema_migration_creates_person_and_membership_tables(): void
    {
        $sql = (new MembershipSchemaMigration('wp_', 'DEFAULT CHARSET utf8mb4'))->statements();

        self::assertSame(2, (new MembershipSchemaMigration('wp_', ''))->version());
        self::assertStringContainsString('CREATE TABLE wp_assoc_person', $sql);
        self::assertStringContainsString('CREATE TABLE wp_assoc_membership', $sql);
        self::assertStringContainsString('UNIQUE KEY membership_number', $sql);
        self::assertStringNotContainsString('wp_users', $sql);
    }

    private function service(bool $allowed, ?RecordingOpenAssignments $closer = null): PeopleService
    {
        return new PeopleService(
            new MemoryPersonRepository(),
            new MemoryMembershipRepository(),
            new MembershipLedger(),
            new class ($allowed) implements Authorizer {
                public function __construct(private bool $allowed)
                {
                }

                public function allows(string $capability): bool
                {
                    if (! $this->allowed) {
                        return false;
                    }

                    return in_array($capability, [Capabilities::EDIT_MEMBERS, Capabilities::VIEW_MEMBERS], true);
                }
            },
            new class implements Transaction {
                public function run(callable $callback): mixed
                {
                    return $callback();
                }
            },
            $closer ?? new RecordingOpenAssignments()
        );
    }
}

final class RecordingOpenAssignments implements OpenBoardAssignments
{
    /** @var list<array{0: int, 1: string}> */
    public array $calls = [];

    public function endOpen(int $personId, AssociationDate $on): void
    {
        $this->calls[] = [$personId, $on->iso()];
    }
}

final class MemoryPersonRepository implements PersonRepository
{
    /** @var array<int, Person> */
    public array $people = [];

    private int $nextId = 1;

    public function add(Person $person): Person
    {
        $saved = $person->withId($this->nextId);
        $this->people[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function save(Person $person): void
    {
        $id = $person->id();

        if ($id === null || ! isset($this->people[$id])) {
            throw new \RuntimeException('Person was not found.');
        }

        $this->people[$id] = $person;
    }

    public function find(int $id): ?Person
    {
        return $this->people[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->people);
    }
}

final class MemoryMembershipRepository implements MembershipRepository
{
    /** @var array<int, MembershipPeriod> */
    public array $periods = [];

    private int $nextId = 1;

    public function add(MembershipPeriod $period): MembershipPeriod
    {
        $saved = $period->withId($this->nextId);
        $this->periods[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function save(MembershipPeriod $period): void
    {
        $id = $period->id();

        if ($id === null || ! isset($this->periods[$id])) {
            throw new \RuntimeException('Membership was not found.');
        }

        $this->periods[$id] = $period;
    }

    public function find(int $id): ?MembershipPeriod
    {
        return $this->periods[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->periods);
    }
}
