<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Meeting\MeetingRecord;
use Foreningssystem\Application\Meeting\MeetingService;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\ActionItem;
use Foreningssystem\Domain\Meeting\ActionItemRepository;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\AgendaItem;
use Foreningssystem\Domain\Meeting\Decision;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\DecisionRepository;
use Foreningssystem\Domain\Meeting\MeetingLifecycle;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingNote;
use Foreningssystem\Domain\Meeting\MeetingNoteRepository;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MeetingType;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use Foreningssystem\Infrastructure\Persistence\ActionItemSchemaMigration;
use Foreningssystem\Infrastructure\Persistence\MeetingRecordSchemaMigration;
use PHPUnit\Framework\TestCase;

final class MeetingRecordTest extends TestCase
{
    public function test_notes_and_decisions_keep_their_identity_when_follow_up_changes(): void
    {
        [$meetings, $record, $people] = $this->world([
            Capabilities::RECORD_MEETING,
            Capabilities::MANAGE_MEETINGS,
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ]);
        $personId = (int) $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null))->id();
        $meetingId = $meetings->schedule(1, 'Styrelsemöte', MeetingMoment::fromLocal('2024-05-02 18:00'), '');
        $itemId = $this->agenda->add(new AgendaItem(null, $meetingId, 1, 'Inköp', ''))->id();
        $otherMeetingId = $meetings->schedule(1, 'Annat', MeetingMoment::fromLocal('2024-06-01 18:00'), '');
        $otherItemId = (int) $this->agenda->add(new AgendaItem(null, $otherMeetingId, 1, 'Annan punkt', ''))->id();

        $noteId = $record->addNote($meetingId, (int) $itemId, 'Tre offerter granskades.', true);
        $record->addNote($meetingId, null, 'Lokalen var bokad.', false);

        try {
            $record->addNote($meetingId, $otherItemId, 'Fel punkt.', false);
            self::fail('A note should not attach to another meeting.');
        } catch (MeetingRuleException) {
        }

        $decisionId = $record->addDecision(
            $meetingId,
            (int) $itemId,
            'Föreningen köper modell X.',
            $personId,
            AssociationDate::fromIso('2026-10-30')
        );
        $record->setFollowUp($decisionId, DecisionFollowUp::Done);
        $decision = $this->decision($record, $meetingId, $decisionId);
        self::assertSame($decisionId, $decision->id());
        self::assertSame('Föreningen köper modell X.', $decision->wording());
        self::assertSame(DecisionFollowUp::Done, $decision->followUp());
        self::assertSame('Ada Lovelace', $this->responsibleName($record, $meetingId, $decisionId));
        self::assertSame(0, $record->openCount());

        $record->reviseDecision($decisionId, 'Föreningen köper modell Y.', $personId, AssociationDate::fromIso('2026-10-30'));
        $revised = $this->decision($record, $meetingId, $decisionId);
        self::assertSame($decisionId, $revised->id());
        self::assertSame('Föreningen köper modell Y.', $revised->wording());
        self::assertSame(DecisionFollowUp::Done, $revised->followUp());

        $meetings->markHeld($meetingId);
        $record->addNote($meetingId, (int) $itemId, 'Sen anteckning.', false);
        self::assertSame(MeetingStatus::Held, $this->meetingStatus($meetings, $meetingId));

        $included = 0;

        foreach ($record->notes($meetingId) as $note) {
            if ($note->id() === $noteId) {
                self::assertTrue($note->includeInMinutes());
                self::assertSame((int) $itemId, $note->agendaItemId());
            }

            if ($note->includeInMinutes()) {
                $included++;
            }

            self::assertStringNotContainsString('@', $note->body());
        }

        self::assertSame(1, $included);
    }

    public function test_an_action_item_keeps_its_text_when_it_is_marked_done(): void
    {
        [$meetings, $record, $people] = $this->world([
            Capabilities::RECORD_MEETING,
            Capabilities::MANAGE_MEETINGS,
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ]);
        $personId = (int) $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null))->id();
        $meetingId = $meetings->schedule(1, 'Styrelsemöte', MeetingMoment::fromLocal('2024-05-02 18:00'), '');
        $itemId = (int) $this->agenda->add(new AgendaItem(null, $meetingId, 1, 'Lokal', ''))->id();
        $otherMeetingId = $meetings->schedule(1, 'Annat', MeetingMoment::fromLocal('2024-06-01 18:00'), '');
        $otherItemId = (int) $this->agenda->add(new AgendaItem(null, $otherMeetingId, 1, 'Annan punkt', ''))->id();

        $decisionId = $record->addDecision($meetingId, $itemId, 'Föreningen förlänger hyresavtalet.', null, null);
        $actionId = $record->addActionItem(
            $meetingId,
            $itemId,
            'Ada kontaktar kommunen om hyresavtalet.',
            $personId,
            AssociationDate::fromIso('2026-11-15')
        );

        try {
            $record->addActionItem($meetingId, $otherItemId, 'Fel punkt.', null, null);
            self::fail('An action item should not attach to another meeting.');
        } catch (MeetingRuleException) {
        }

        $record->setActionStatus($actionId, ActionStatus::Done);
        $action = $this->actionItem($record, $meetingId, $actionId);
        $decision = $this->decision($record, $meetingId, $decisionId);

        self::assertSame($actionId, $action->id());
        self::assertSame('Ada kontaktar kommunen om hyresavtalet.', $action->task());
        self::assertSame(ActionStatus::Done, $action->status());
        self::assertSame('Ada Lovelace', $this->assigneeName($record, $meetingId, $actionId));
        self::assertStringNotContainsString('@', (string) $this->assigneeName($record, $meetingId, $actionId));
        self::assertSame(DecisionFollowUp::Open, $decision->followUp());
        self::assertSame('Föreningen förlänger hyresavtalet.', $decision->wording());
        self::assertSame(1, $record->openCount());
        self::assertSame(0, $record->openActionCount());

        $meetings->markHeld($meetingId);
        $lateId = $record->addActionItem($meetingId, $itemId, 'Skicka underlaget efter mötet.', null, null);
        self::assertSame(ActionStatus::Open, $this->actionItem($record, $meetingId, $lateId)->status());
        self::assertSame(1, $record->openActionCount());
    }

    public function test_planning_a_meeting_does_not_allow_action_items(): void
    {
        [, $record] = $this->world([Capabilities::MANAGE_MEETINGS, Capabilities::VIEW_INTERNAL_MEETINGS]);

        $this->expectException(NotAllowed::class);
        $record->addActionItem(1, null, 'Ring kommunen.', null, null);
    }

    public function test_schema_migration_creates_an_action_item_table(): void
    {
        $migration = new ActionItemSchemaMigration('wp_', '');
        $sql = $migration->statements();

        self::assertSame(7, $migration->version());
        self::assertStringContainsString('CREATE TABLE wp_assoc_action_item', $sql);
        self::assertStringContainsString('assignee_person_id', $sql);
        self::assertStringContainsString('due_on', $sql);
        self::assertStringNotContainsString('wp_users', $sql);
        self::assertStringNotContainsString('assoc_decision', $sql);
        self::assertStringNotContainsString('assoc_minutes', $sql);
    }

    public function test_planning_a_meeting_does_not_allow_notes(): void
    {
        [, $record] = $this->world([Capabilities::MANAGE_MEETINGS, Capabilities::VIEW_INTERNAL_MEETINGS]);

        $this->expectException(NotAllowed::class);
        $record->addNote(1, null, 'Anteckning', false);
    }

    public function test_schema_migration_creates_note_and_decision_tables(): void
    {
        $migration = new MeetingRecordSchemaMigration('wp_', '');
        $sql = $migration->statements();

        self::assertSame(6, $migration->version());
        self::assertStringContainsString('CREATE TABLE wp_assoc_meeting_note', $sql);
        self::assertStringContainsString('CREATE TABLE wp_assoc_decision', $sql);
        self::assertStringContainsString('include_in_minutes', $sql);
        self::assertStringContainsString('follow_up', $sql);
        self::assertStringNotContainsString('wp_users', $sql);
        self::assertStringNotContainsString('assoc_minutes', $sql);
    }

    private MemoryAgendaRepository $agenda;

    private function decision(MeetingRecord $record, int $meetingId, int $decisionId): Decision
    {
        foreach ($record->decisions($meetingId) as $row) {
            if ($row->decision()->id() === $decisionId) {
                return $row->decision();
            }
        }

        self::fail('Decision was not found.');
    }

    private function responsibleName(MeetingRecord $record, int $meetingId, int $decisionId): ?string
    {
        foreach ($record->decisions($meetingId) as $row) {
            if ($row->decision()->id() === $decisionId) {
                return $row->responsibleName();
            }
        }

        return null;
    }

    private function actionItem(MeetingRecord $record, int $meetingId, int $actionId): ActionItem
    {
        foreach ($record->actionItems($meetingId) as $row) {
            if ($row->item()->id() === $actionId) {
                return $row->item();
            }
        }

        self::fail('Action item was not found.');
    }

    private function assigneeName(MeetingRecord $record, int $meetingId, int $actionId): ?string
    {
        foreach ($record->actionItems($meetingId) as $row) {
            if ($row->item()->id() === $actionId) {
                return $row->assigneeName();
            }
        }

        return null;
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

    /**
     * @param list<string> $capabilities
     * @return array{0: MeetingService, 1: MeetingRecord, 2: MemoryPersonRepository}
     */
    private function world(array $capabilities): array
    {
        $types = new MemoryMeetingTypeRepository();
        $types->add(new MeetingType(null, 'board_meeting', 'Styrelsemöte', 10));
        $meetings = new MemoryMeetingRepository();
        $people = new MemoryPersonRepository();
        $this->agenda = new MemoryAgendaRepository();
        $authorizer = new class ($capabilities) implements Authorizer {
            /** @param list<string> $capabilities */
            public function __construct(private array $capabilities)
            {
            }

            public function allows(string $capability): bool
            {
                return in_array($capability, $this->capabilities, true);
            }
        };
        $transaction = new class implements Transaction {
            public function run(callable $callback): mixed
            {
                return $callback();
            }
        };

        return [
            new MeetingService($types, $meetings, new MeetingLifecycle(), $authorizer, $transaction),
            new MeetingRecord($meetings, $this->agenda, $people, new MemoryNoteRepository(), new MemoryDecisionRepository(), new MemoryActionItemRepository(), $authorizer, $transaction),
            $people,
        ];
    }
}

final class MemoryNoteRepository implements MeetingNoteRepository
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
        $id = $note->id();

        if ($id === null || ! isset($this->notes[$id])) {
            throw new \RuntimeException('Note was not found.');
        }

        $this->notes[$id] = $note;
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

final class MemoryDecisionRepository implements DecisionRepository
{
    /** @var array<int, Decision> */
    public array $decisions = [];

    private int $nextId = 1;

    public function add(Decision $decision): Decision
    {
        $saved = $decision->withId($this->nextId);
        $this->decisions[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function save(Decision $decision): void
    {
        $id = $decision->id();

        if ($id === null || ! isset($this->decisions[$id])) {
            throw new \RuntimeException('Decision was not found.');
        }

        $this->decisions[$id] = $decision;
    }

    public function remove(int $id): void
    {
        unset($this->decisions[$id]);
    }

    public function find(int $id): ?Decision
    {
        return $this->decisions[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->decisions);
    }

    public function forMeeting(int $meetingId): array
    {
        $rows = [];

        foreach ($this->decisions as $decision) {
            if ($decision->meetingId() === $meetingId) {
                $rows[] = $decision;
            }
        }

        return $rows;
    }
}

final class MemoryActionItemRepository implements ActionItemRepository
{
    /** @var array<int, ActionItem> */
    public array $items = [];

    private int $nextId = 1;

    public function add(ActionItem $item): ActionItem
    {
        $saved = $item->withId($this->nextId);
        $this->items[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function save(ActionItem $item): void
    {
        $id = $item->id();

        if ($id === null || ! isset($this->items[$id])) {
            throw new \RuntimeException('Action item was not found.');
        }

        $this->items[$id] = $item;
    }

    public function remove(int $id): void
    {
        unset($this->items[$id]);
    }

    public function find(int $id): ?ActionItem
    {
        return $this->items[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->items);
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
