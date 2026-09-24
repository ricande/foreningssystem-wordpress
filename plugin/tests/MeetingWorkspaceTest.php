<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Meeting\MeetingService;
use Foreningssystem\Application\Meeting\MeetingWorkspace;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\AgendaItem;
use Foreningssystem\Domain\Meeting\AgendaOrder;
use Foreningssystem\Domain\Meeting\AgendaRepository;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingLifecycle;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRoster;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MeetingType;
use Foreningssystem\Domain\Meeting\Participant;
use Foreningssystem\Domain\Meeting\ParticipantRepository;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use Foreningssystem\Infrastructure\Persistence\MeetingRosterSchemaMigration;
use PHPUnit\Framework\TestCase;

final class MeetingWorkspaceTest extends TestCase
{
    public function test_attendance_and_agenda_stay_editable_after_the_meeting_is_held(): void
    {
        [$meetings, $workspace] = $this->world([
            Capabilities::MANAGE_MEETINGS,
            Capabilities::RECORD_MEETING,
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ]);
        $guest = (int) $this->people->add(new Person(null, 'Bo', 'Gäst', 'bo@example.test', PersonStatus::Known, null))->id();
        $member = (int) $this->people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null))->id();
        $meetingId = $meetings->schedule(1, 'Styrelsemöte', MeetingMoment::fromLocal('2024-05-02 18:00'), '');

        $workspace->addParticipant($meetingId, $guest, Presence::CoOpted, MeetingDuty::None);
        $workspace->addParticipant($meetingId, $member, Presence::Present, MeetingDuty::Chair);

        try {
            $workspace->addParticipant($meetingId, $member, Presence::Absent, MeetingDuty::None);
            self::fail('The same person should not be added twice.');
        } catch (MeetingRuleException) {
            self::assertCount(2, $workspace->attendance($meetingId));
        }

        $first = $workspace->addAgendaItem($meetingId, 'Öppnande', '');
        $workspace->addAgendaItem($meetingId, 'Avslut', '');
        $override = $workspace->addAgendaItem($meetingId, 'Val', '5 a');
        self::assertSame(['1', '2', '5 a'], $this->numbers($workspace, $meetingId));

        $workspace->moveAgendaItem($first, 1);
        self::assertSame(['Avslut', 'Öppnande', 'Val'], $this->titles($workspace, $meetingId));
        self::assertSame(['1', '2', '5 a'], $this->numbers($workspace, $meetingId));

        $workspace->removeAgendaItem($override);
        self::assertSame(['1', '2'], $this->numbers($workspace, $meetingId));

        $meetings->markHeld($meetingId);
        $workspace->addAgendaItem($meetingId, 'Sen fråga', '');
        self::assertSame(MeetingStatus::Held, $this->meetingStatus($meetings, $meetingId));
        self::assertSame(['Avslut', 'Öppnande', 'Sen fråga'], $this->titles($workspace, $meetingId));

        foreach ($workspace->attendance($meetingId) as $row) {
            self::assertStringNotContainsString('@', $row->personName());
        }
    }

    public function test_viewing_a_meeting_does_not_allow_editing_it(): void
    {
        [$meetings] = $this->world([
            Capabilities::MANAGE_MEETINGS,
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ]);
        $meetingId = $meetings->schedule(1, 'Styrelsemöte', MeetingMoment::fromLocal('2024-05-02 18:00'), '');
        $viewer = new MeetingWorkspace(
            $this->meetingRows,
            $this->people,
            $this->participants,
            $this->agendaItems,
            new MeetingRoster(),
            new AgendaOrder(),
            $this->authorizer([Capabilities::VIEW_INTERNAL_MEETINGS]),
            $this->transaction()
        );

        $this->expectException(NotAllowed::class);
        $viewer->addAgendaItem($meetingId, 'Öppnande', '');
    }

    public function test_schema_migration_creates_participant_and_agenda_tables(): void
    {
        $migration = new MeetingRosterSchemaMigration('wp_', '');
        $sql = $migration->statements();

        self::assertSame(5, $migration->version());
        self::assertStringContainsString('CREATE TABLE wp_assoc_meeting_participant', $sql);
        self::assertStringContainsString('CREATE TABLE wp_assoc_agenda_item', $sql);
        self::assertStringContainsString('UNIQUE KEY meeting_person', $sql);
        self::assertStringNotContainsString('wp_users', $sql);
        self::assertStringNotContainsString('decision', $sql);
    }

    /**
     * @return list<string>
     */
    private function numbers(MeetingWorkspace $workspace, int $meetingId): array
    {
        return array_map(static fn (AgendaItem $item): string => $item->displayNumber(), $workspace->agenda($meetingId));
    }

    /**
     * @return list<string>
     */
    private function titles(MeetingWorkspace $workspace, int $meetingId): array
    {
        return array_map(static fn (AgendaItem $item): string => $item->title(), $workspace->agenda($meetingId));
    }

    private function meetingStatus(MeetingService $meetings, int $meetingId): MeetingStatus
    {
        foreach ($meetings->listMeetings() as $meeting) {
            if ($meeting->id() === $meetingId) {
                return $meeting->status();
            }
        }

        self::fail('Meeting was not found.');
    }

    private MemoryPersonRepository $people;

    private MemoryMeetingRepository $meetingRows;

    private MemoryParticipantRepository $participants;

    private MemoryAgendaRepository $agendaItems;

    /**
     * @param list<string> $capabilities
     * @return array{0: MeetingService, 1: MeetingWorkspace}
     */
    private function world(array $capabilities): array
    {
        $types = new MemoryMeetingTypeRepository();
        $types->add(new MeetingType(null, 'board_meeting', 'Styrelsemöte', 10));
        $this->meetingRows = new MemoryMeetingRepository();
        $this->people = new MemoryPersonRepository();
        $this->participants = new MemoryParticipantRepository();
        $this->agendaItems = new MemoryAgendaRepository();
        $authorizer = $this->authorizer($capabilities);
        $transaction = $this->transaction();
        $service = new MeetingService($types, $this->meetingRows, new MeetingLifecycle(), $authorizer, $transaction);
        $workspace = new MeetingWorkspace(
            $this->meetingRows,
            $this->people,
            $this->participants,
            $this->agendaItems,
            new MeetingRoster(),
            new AgendaOrder(),
            $authorizer,
            $transaction
        );

        return [$service, $workspace];
    }

    /**
     * @param list<string> $capabilities
     */
    private function authorizer(array $capabilities): Authorizer
    {
        return new class ($capabilities) implements Authorizer {
            /** @param list<string> $capabilities */
            public function __construct(private array $capabilities)
            {
            }

            public function allows(string $capability): bool
            {
                return in_array($capability, $this->capabilities, true);
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

final class MemoryParticipantRepository implements ParticipantRepository
{
    /** @var array<int, Participant> */
    public array $participants = [];

    private int $nextId = 1;

    public function add(Participant $participant): Participant
    {
        $saved = $participant->withId($this->nextId);
        $this->participants[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function remove(int $id): void
    {
        unset($this->participants[$id]);
    }

    public function find(int $id): ?Participant
    {
        return $this->participants[$id] ?? null;
    }

    public function forMeeting(int $meetingId): array
    {
        $rows = [];

        foreach ($this->participants as $participant) {
            if ($participant->meetingId() === $meetingId) {
                $rows[] = $participant;
            }
        }

        return $rows;
    }
}

final class MemoryAgendaRepository implements AgendaRepository
{
    /** @var array<int, AgendaItem> */
    public array $items = [];

    private int $nextId = 1;

    public function add(AgendaItem $item): AgendaItem
    {
        $saved = $item->withId($this->nextId);
        $this->items[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function save(AgendaItem $item): void
    {
        $id = $item->id();

        if ($id === null || ! isset($this->items[$id])) {
            throw new \RuntimeException('Agenda item was not found.');
        }

        $this->items[$id] = $item;
    }

    public function remove(int $id): void
    {
        unset($this->items[$id]);
    }

    public function find(int $id): ?AgendaItem
    {
        return $this->items[$id] ?? null;
    }

    public function forMeeting(int $meetingId): array
    {
        $rows = [];

        foreach ($this->items as $item) {
            if ($item->meetingId() === $meetingId) {
                $rows[] = $item;
            }
        }

        return $rows;
    }
}
