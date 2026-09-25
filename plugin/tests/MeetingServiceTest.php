<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Meeting\MeetingService;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingLifecycle;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRepository;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MeetingType;
use Foreningssystem\Domain\Meeting\MeetingTypeRepository;
use Foreningssystem\Infrastructure\Persistence\MeetingSchemaMigration;
use PHPUnit\Framework\TestCase;

final class MeetingServiceTest extends TestCase
{
    public function test_a_meeting_can_be_held_and_its_header_can_still_change(): void
    {
        $service = $this->service([Capabilities::MANAGE_MEETINGS, Capabilities::RECORD_MEETING, Capabilities::VIEW_INTERNAL_MEETINGS]);
        $typeId = $this->type($service);
        $when = MeetingMoment::fromLocal('2024-05-02 18:00');
        $id = $service->schedule($typeId, '  Styrelsemöte  ', $when, 'Föreningslokalen');

        self::assertSame(MeetingStatus::Planned, $this->only($service)->status());
        self::assertSame('Styrelsemöte', $this->only($service)->title());
        self::assertSame(1, $service->countWithStatus(MeetingStatus::Planned));

        $service->start($id);
        self::assertSame(MeetingStatus::InProgress, $this->only($service)->status());

        $service->markHeld($id);
        $service->updateHeader($id, $typeId, 'Styrelsemöte', $when, 'Biblioteket');
        $held = $this->only($service);
        self::assertSame(MeetingStatus::Held, $held->status());
        self::assertSame('Biblioteket', $held->place());
        self::assertSame('18:00', $held->startsAt()->time());

        try {
            $service->start($id);
            self::fail('A held meeting should not start again.');
        } catch (MeetingRuleException) {
            self::assertSame(MeetingStatus::Held, $this->only($service)->status());
        }
    }

    public function test_a_meeting_recorded_afterwards_can_skip_in_progress(): void
    {
        $service = $this->service([Capabilities::MANAGE_MEETINGS, Capabilities::RECORD_MEETING, Capabilities::VIEW_INTERNAL_MEETINGS]);
        $id = $service->schedule($this->type($service), 'Arbetsmöte', MeetingMoment::fromLocal('2024-06-01 10:00'), '');

        $service->markHeld($id);

        self::assertSame(MeetingStatus::Held, $this->only($service)->status());
        self::assertSame(0, $service->countWithStatus(MeetingStatus::Planned));
    }

    public function test_planning_and_recording_are_different_capabilities(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $meetings = new MemoryMeetingRepository();
        $typeId = (int) $types->add(new MeetingType(null, 'board_meeting', 'Styrelsemöte', 10))->id();
        $planner = $this->serviceFor($types, $meetings, [Capabilities::MANAGE_MEETINGS, Capabilities::VIEW_INTERNAL_MEETINGS]);
        $recorder = $this->serviceFor($types, $meetings, [Capabilities::RECORD_MEETING, Capabilities::VIEW_INTERNAL_MEETINGS]);
        $viewer = $this->serviceFor($types, $meetings, [Capabilities::VIEW_INTERNAL_MEETINGS]);
        $outsider = $this->serviceFor($types, $meetings, []);
        $id = $planner->schedule($typeId, 'Styrelsemöte', MeetingMoment::fromLocal('2024-05-02 18:00'), '');

        try {
            $recorder->schedule($typeId, 'Annat', MeetingMoment::fromLocal('2024-05-03 18:00'), '');
            self::fail('Recording a meeting should not be enough to plan one.');
        } catch (NotAllowed) {
        }

        $recorder->start($id);
        self::assertSame(MeetingStatus::InProgress, $meetings->find($id)?->status());

        try {
            $outsider->listMeetings();
            self::fail('A user without view_internal_meetings should not see meetings.');
        } catch (NotAllowed) {
        }

        self::assertCount(1, $viewer->listMeetings());
    }

    public function test_schema_migration_creates_meeting_tables_without_an_agenda(): void
    {
        $migration = new MeetingSchemaMigration('wp_', '');
        $sql = $migration->statements();
        $slugs = array_column($migration->suggestedTypes(), 'slug');

        self::assertSame(4, $migration->version());
        self::assertStringContainsString('CREATE TABLE wp_assoc_meeting_type', $sql);
        self::assertStringContainsString('CREATE TABLE wp_assoc_meeting', $sql);
        self::assertStringNotContainsString('wp_users', $sql);
        self::assertStringNotContainsString('agenda', $sql);
        self::assertContains('board_meeting', $slugs);
        self::assertContains('annual_meeting', $slugs);
    }

    private function only(MeetingService $service): Meeting
    {
        $meetings = $service->listMeetings();
        self::assertCount(1, $meetings);

        return $meetings[0];
    }

    private function type(MeetingService $service): int
    {
        foreach ($service->types() as $type) {
            if ($type->id() !== null) {
                return $type->id();
            }
        }

        self::fail('Meeting type was not found.');
    }

    /**
     * @param list<string> $capabilities
     */
    private function service(array $capabilities): MeetingService
    {
        $types = new MemoryMeetingTypeRepository();
        $types->add(new MeetingType(null, 'board_meeting', 'Styrelsemöte', 10));

        return $this->serviceFor($types, new MemoryMeetingRepository(), $capabilities);
    }

    /**
     * @param list<string> $capabilities
     */
    private function serviceFor(MemoryMeetingTypeRepository $types, MemoryMeetingRepository $meetings, array $capabilities): MeetingService
    {
        return new MeetingService($types, $meetings, new MeetingLifecycle(), new class ($capabilities) implements Authorizer {
            /** @param list<string> $capabilities */
            public function __construct(private array $capabilities)
            {
            }

            public function allows(string $capability): bool
            {
                return in_array($capability, $this->capabilities, true);
            }
        }, new class implements Transaction {
            public function run(callable $callback): mixed
            {
                return $callback();
            }
        });
    }
}

final class MemoryMeetingTypeRepository implements MeetingTypeRepository
{
    /** @var array<int, MeetingType> */
    public array $types = [];

    private int $nextId = 1;

    public bool $failNextWrite = false;

    public function add(MeetingType $type): MeetingType
    {
        $this->guardWrite();
        $saved = $type->withId($this->nextId);
        $this->types[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function save(MeetingType $type): void
    {
        $this->guardWrite();
        $id = $type->id();

        if ($id === null || ! isset($this->types[$id])) {
            throw new \RuntimeException('The meeting type could not be saved.');
        }

        $this->types[$id] = $type;
    }

    private function guardWrite(): void
    {
        if (! $this->failNextWrite) {
            return;
        }

        $this->failNextWrite = false;

        throw new \RuntimeException('The meeting type could not be saved.');
    }

    public function find(int $id): ?MeetingType
    {
        return $this->types[$id] ?? null;
    }

    public function findBySlug(string $slug): ?MeetingType
    {
        foreach ($this->types as $type) {
            if ($type->slug() === $slug) {
                return $type;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_values($this->types);
    }
}

final class MemoryMeetingRepository implements MeetingRepository
{
    /** @var array<int, Meeting> */
    public array $meetings = [];

    private int $nextId = 1;

    public function add(Meeting $meeting): Meeting
    {
        $saved = $meeting->withId($this->nextId);
        $this->meetings[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function save(Meeting $meeting): void
    {
        $id = $meeting->id();

        if ($id === null || ! isset($this->meetings[$id])) {
            throw new \RuntimeException('Meeting was not found.');
        }

        $this->meetings[$id] = $meeting;
    }

    public function find(int $id): ?Meeting
    {
        return $this->meetings[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->meetings);
    }
}
