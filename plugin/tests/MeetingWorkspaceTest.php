<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Meeting\MeetingService;
use Foreningssystem\Application\Meeting\MeetingWorkspace;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\ActionItem;
use Foreningssystem\Domain\Meeting\ActionItemRepository;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\AgendaItem;
use Foreningssystem\Domain\Meeting\AgendaOrder;
use Foreningssystem\Domain\Meeting\AgendaRepository;
use Foreningssystem\Domain\Meeting\Decision;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\DecisionRepository;
use Foreningssystem\Domain\Meeting\MeetingNote;
use Foreningssystem\Domain\Meeting\MeetingNoteRepository;
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

    public function test_a_deceased_person_is_not_added_and_linked_agenda_items_stay(): void
    {
        [$meetings, $workspace] = $this->world([
            Capabilities::MANAGE_MEETINGS,
            Capabilities::RECORD_MEETING,
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ]);
        $deceased = (int) $this->people->add(new Person(null, 'Nils', 'Berg', 'nils@example.test', PersonStatus::Deceased, null))->id();
        $meetingId = $meetings->schedule(1, 'Styrelsemöte', MeetingMoment::fromLocal('2026-10-02 18:00'), 'Lokalen');
        $otherId = $meetings->schedule(1, 'Annat', MeetingMoment::fromLocal('2026-11-02 18:00'), '');
        $itemId = $workspace->addAgendaItem($meetingId, 'Inköp', '');
        $otherItem = $workspace->addAgendaItem($otherId, 'Annan punkt', '');

        try {
            $workspace->addParticipant($meetingId, $deceased, Presence::Present, MeetingDuty::None);
            self::fail('A deceased person should not be added.');
        } catch (MeetingRuleException $error) {
            self::assertSame('A deceased person cannot be added to a meeting.', $error->getMessage());
        }

        self::assertSame([], $workspace->attendance($meetingId));
        $this->notes->add(new MeetingNote(null, $meetingId, $itemId, 'Tre offerter.', false));

        try {
            $workspace->removeAgendaItem($itemId, $meetingId);
            self::fail('An item with a note should stay.');
        } catch (MeetingRuleException $error) {
            self::assertSame('Remove the notes, decisions, and tasks on this item before removing it.', $error->getMessage());
        }

        self::assertNotNull($this->agendaItems->find($itemId));

        try {
            $workspace->moveAgendaItem($otherItem, -1, $meetingId);
            self::fail('Another meeting agenda item should not move.');
        } catch (MeetingRuleException $error) {
            self::assertSame('The record does not belong to this meeting.', $error->getMessage());
        }

        try {
            $workspace->removeAgendaItem($otherItem, $meetingId);
            self::fail('Another meeting agenda item should not be removed.');
        } catch (MeetingRuleException $error) {
            self::assertSame('The record does not belong to this meeting.', $error->getMessage());
        }

        self::assertSame('Annan punkt', $this->agendaItems->find($otherItem)?->title());
        $ada = (int) $this->people->add(new Person(null, 'Ada', 'Lovelace', 'ada-cross@example.test', PersonStatus::Known, null))->id();
        $workspace->addParticipant($otherId, $ada, Presence::Present, MeetingDuty::None);
        $participantId = (int) $workspace->attendance($otherId)[0]->participant()->id();

        try {
            $workspace->removeParticipant($participantId, $meetingId);
            self::fail('A participant from another meeting should stay.');
        } catch (MeetingRuleException $error) {
            self::assertSame('The record does not belong to this meeting.', $error->getMessage());
        }

        self::assertCount(1, $workspace->attendance($otherId));
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
            new WorkspaceNoteRepository(),
            new WorkspaceDecisionRepository(),
            new WorkspaceActionRepository(),
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

    private WorkspaceNoteRepository $notes;

    private WorkspaceDecisionRepository $decisions;

    private WorkspaceActionRepository $actions;

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
        $this->notes = new WorkspaceNoteRepository();
        $this->decisions = new WorkspaceDecisionRepository();
        $this->actions = new WorkspaceActionRepository();
        $workspace = new MeetingWorkspace(
            $this->meetingRows,
            $this->people,
            $this->participants,
            $this->agendaItems,
            $this->notes,
            $this->decisions,
            $this->actions,
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

    public function forPerson(int $personId): array
    {
        $rows = [];

        foreach ($this->participants as $participant) {
            if ($participant->personId() === $personId) {
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

final class WorkspaceNoteRepository implements MeetingNoteRepository
{
    /** @var array<int, MeetingNote> */
    public array $notes = [];

    private int $nextId = 1;

    public function add(MeetingNote $note): MeetingNote
    {
        $saved = $note->withId($this->nextId);
        $this->notes[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function save(MeetingNote $note): void
    {
    }

    public function remove(int $id): void
    {
        unset($this->notes[$id]);
    }

    public function find(int $id): ?MeetingNote
    {
        return $this->notes[$id] ?? null;
    }

    public function forMeeting(int $meetingId): array
    {
        $rows = [];

        foreach ($this->notes as $note) {
            if ($note->meetingId() === $meetingId) {
                $rows[] = $note;
            }
        }

        return $rows;
    }
}

final class WorkspaceDecisionRepository implements DecisionRepository
{
    public function add(Decision $decision): Decision
    {
        return $decision;
    }

    public function save(Decision $decision): void
    {
    }

    public function remove(int $id): void
    {
    }

    public function find(int $id): ?Decision
    {
        return null;
    }

    public function all(): array
    {
        return [];
    }

    public function forMeeting(int $meetingId): array
    {
        return [];
    }
}

final class WorkspaceActionRepository implements ActionItemRepository
{
    public function add(ActionItem $item): ActionItem
    {
        return $item;
    }

    public function save(ActionItem $item): void
    {
    }

    public function remove(int $id): void
    {
    }

    public function find(int $id): ?ActionItem
    {
        return null;
    }

    public function all(): array
    {
        return [];
    }

    public function forMeeting(int $meetingId): array
    {
        return [];
    }
}
