<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Association\WorkOverview;
use Foreningssystem\Application\Meeting\MeetingRecord;
use Foreningssystem\Application\Meeting\MeetingService;
use Foreningssystem\Application\Meeting\MinutesComposer;
use Foreningssystem\Application\Meeting\MinutesDrafts;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Application\Task\AgendaPresentation;
use Foreningssystem\Application\Task\AssigneePresentation;
use Foreningssystem\Application\Task\TaskRegister;
use Foreningssystem\Application\Task\TaskRegisterQuery;
use Foreningssystem\Application\Task\TaskRegisterSnapshot;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\ActionItem;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\AgendaItem;
use Foreningssystem\Domain\Meeting\AgendaOrder;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingLifecycle;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MeetingType;
use Foreningssystem\Domain\Meeting\MinutesLifecycle;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use Foreningssystem\Infrastructure\WordPress\TasksPage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TaskRegisterTest extends TestCase
{
    private const TODAY = '2026-09-25';

    public function test_viewing_internal_meetings_can_read_the_register(): void
    {
        $snapshot = $this->sample()['register']->snapshot($this->today(), TaskRegisterQuery::defaults());

        self::assertGreaterThan(0, $snapshot->openCount + $snapshot->doneCount);
    }

    public function test_a_user_without_internal_meetings_cannot_read_the_register(): void
    {
        try {
            $this->sample()['hidden']->snapshot($this->today(), TaskRegisterQuery::defaults());
            self::fail('The register should require view_internal_meetings.');
        } catch (NotAllowed $error) {
            self::assertSame(Capabilities::VIEW_INTERNAL_MEETINGS, $error->getMessage());
        }
    }

    public function test_every_stored_task_keeps_its_identity_and_source(): void
    {
        $world = $this->sample();
        $snapshot = $world['register']->snapshot($this->today(), TaskRegisterQuery::normalize('all', null, null, 1));
        $ids = array_map(static fn ($row) => $row->actionItemId, $snapshot->rows);

        self::assertEqualsCanonicalizing(array_map(static fn (ActionItem $item): int => (int) $item->id(), $world['items']->all()), $ids);

        $roof = $this->row($snapshot, (int) $world['roof']->id());
        self::assertSame($world['roof']->task(), $roof->task);
        self::assertSame('Board meeting', $roof->meetingTitle);
        self::assertSame('2026-09-01', $roof->meetingDate);
        self::assertSame(MeetingStatus::Held, $roof->meetingStatus);
        self::assertSame('§ 7', $roof->agendaNumber);
        self::assertSame('Roof repairs', $roof->agendaTitle);
        self::assertSame(AgendaPresentation::Item, $roof->agendaState);

        $future = $this->row($snapshot, (int) $world['future']->id());
        self::assertSame('2', $future->agendaNumber);
        self::assertSame('Newsletter', $future->agendaTitle);

        $level = $this->row($snapshot, (int) $world['level']->id());
        self::assertNull($level->agendaItemId);
        self::assertSame(AgendaPresentation::None, $level->agendaState);
        self::assertNull($level->agendaNumber);

        $missingItem = $this->row($snapshot, (int) $world['missingItem']->id());
        self::assertSame(AgendaPresentation::Unavailable, $missingItem->agendaState);
        self::assertSame('Board meeting', $missingItem->meetingTitle);

        $missingMeeting = $this->row($snapshot, (int) $world['missingMeeting']->id());
        self::assertFalse($missingMeeting->meetingAvailable);
        self::assertNull($missingMeeting->meetingTitle);
        self::assertSame('Orphan task', $missingMeeting->task);
    }

    public function test_assignee_states_stay_distinct_and_a_deceased_person_remains(): void
    {
        $world = $this->sample();
        $snapshot = $world['register']->snapshot($this->today(), TaskRegisterQuery::normalize('all', null, null, 1));

        $assigned = $this->row($snapshot, (int) $world['roof']->id());
        self::assertSame(AssigneePresentation::Assigned, $assigned->assigneeState);
        self::assertSame('Anna Andersson', $assigned->assigneeName);

        $unassigned = $this->row($snapshot, (int) $world['plan']->id());
        self::assertSame(AssigneePresentation::Unassigned, $unassigned->assigneeState);
        self::assertNull($unassigned->assigneePersonId);
        self::assertNull($unassigned->assigneeName);

        $missing = $this->row($snapshot, (int) $world['missingPerson']->id());
        self::assertSame(AssigneePresentation::Unavailable, $missing->assigneeState);
        self::assertNotNull($missing->assigneePersonId);
        self::assertNull($missing->assigneeName);

        $deceased = $this->row($snapshot, (int) $world['memorial']->id());
        self::assertSame('Erik Memorial', $deceased->assigneeName);
        self::assertSame(AssigneePresentation::Assigned, $deceased->assigneeState);
    }

    public function test_overdue_matches_the_work_overview_and_ignores_today_and_done(): void
    {
        $world = $this->sample();
        $snapshot = $world['register']->snapshot($this->today(), TaskRegisterQuery::normalize('all', null, null, 1));

        self::assertTrue($this->row($snapshot, (int) $world['yesterday']->id())->overdue);
        self::assertFalse($this->row($snapshot, (int) $world['todayDue']->id())->overdue);
        self::assertFalse($this->row($snapshot, (int) $world['future']->id())->overdue);
        self::assertFalse($this->row($snapshot, (int) $world['undated']->id())->overdue);
        self::assertFalse($this->row($snapshot, (int) $world['doneOld']->id())->overdue);
        self::assertSame(ActionStatus::Done, $this->row($snapshot, (int) $world['doneOld']->id())->status);

        $fromOverview = array_map(
            static fn (ActionItem $item): int => (int) $item->id(),
            (new WorkOverview())->overdue($world['items']->all(), $this->today())
        );
        $fromRegister = [];

        foreach ($snapshot->rows as $row) {
            if ($row->overdue) {
                $fromRegister[] = $row->actionItemId;
            }
        }

        self::assertEqualsCanonicalizing($fromOverview, $fromRegister);
    }

    public function test_open_and_done_order_does_not_follow_repository_order(): void
    {
        $world = $this->sample();
        $open = $world['register']->snapshot($this->today(), TaskRegisterQuery::defaults());
        $done = $world['register']->snapshot($this->today(), TaskRegisterQuery::normalize('done', null, null, 1));

        self::assertSame($world['openOrder'], array_map(static fn ($row) => $row->actionItemId, $open->rows));
        self::assertSame($world['doneOrder'], array_map(static fn ($row) => $row->actionItemId, $done->rows));
        $storedOpen = [];

        foreach ($world['items']->all() as $item) {
            if ($item->status() === ActionStatus::Open) {
                $storedOpen[] = (int) $item->id();
            }
        }

        self::assertNotSame($storedOpen, $world['openOrder']);
    }

    public function test_filters_default_to_open_and_accept_done_all_assignee_and_overdue(): void
    {
        $world = $this->sample();
        $register = $world['register'];
        $today = $this->today();
        $defaults = $register->snapshot($today, TaskRegisterQuery::defaults());

        self::assertSame(TaskRegisterQuery::OPEN, $defaults->query->status);
        foreach ($defaults->rows as $row) {
            self::assertSame(ActionStatus::Open, $row->status);
        }

        $done = $register->snapshot($today, TaskRegisterQuery::normalize('done', null, null, 1));
        foreach ($done->rows as $row) {
            self::assertSame(ActionStatus::Done, $row->status);
        }

        $all = $register->snapshot($today, TaskRegisterQuery::normalize('all', null, null, 1));
        self::assertSame($defaults->openCount + $defaults->doneCount, $all->matched);
        self::assertSame(ActionStatus::Open, $all->rows[0]->status);
        self::assertSame(ActionStatus::Done, $all->rows[array_key_last($all->rows)]->status);

        $anna = (int) $world['anna']->id();
        $assignee = $register->snapshot($today, TaskRegisterQuery::normalize('all', (string) $anna, null, 1));
        self::assertNotEmpty($assignee->rows);
        foreach ($assignee->rows as $row) {
            self::assertSame($anna, $row->assigneePersonId);
        }

        $unassigned = $register->snapshot($today, TaskRegisterQuery::normalize('all', 'unassigned', null, 1));
        self::assertNotEmpty($unassigned->rows);
        foreach ($unassigned->rows as $row) {
            self::assertSame(AssigneePresentation::Unassigned, $row->assigneeState);
        }

        $overdue = $register->snapshot($today, TaskRegisterQuery::normalize('open', null, 'overdue', 1));
        self::assertNotEmpty($overdue->rows);
        foreach ($overdue->rows as $row) {
            self::assertTrue($row->overdue);
            self::assertSame(ActionStatus::Open, $row->status);
        }

        $doneOverdue = $register->snapshot($today, TaskRegisterQuery::normalize('done', null, 'overdue', 1));
        self::assertSame(0, $doneOverdue->matched);

        $fallback = TaskRegisterQuery::normalize('deleted', 'Ada', 'soon', 0);
        self::assertSame(TaskRegisterQuery::OPEN, $fallback->status);
        self::assertSame(TaskRegisterQuery::ALL, $fallback->assignee);
        self::assertSame(TaskRegisterQuery::ALL, $fallback->urgency);
        self::assertSame(1, $fallback->page);
        self::assertSame($defaults->openCount, $register->snapshot($today, $fallback)->matched);
    }

    public function test_pagination_is_deterministic_and_out_of_range_pages_clamp(): void
    {
        $items = new MemoryActionItemRepository();
        $meetings = new MemoryMeetingRepository();
        $meeting = $meetings->add(new Meeting(null, 1, 'Pages', MeetingMoment::fromLocal('2026-01-01 18:00'), '', MeetingStatus::Held));
        $meetingId = (int) $meeting->id();

        for ($index = 0; $index < 51; $index++) {
            $items->add(new ActionItem(null, $meetingId, null, 'Page task ' . $index, null, null, ActionStatus::Open));
        }

        $register = $this->register($items, $meetings, new MemoryAgendaRepository(), new MemoryPersonRepository(), [Capabilities::VIEW_INTERNAL_MEETINGS]);
        $first = $register->snapshot($this->today(), TaskRegisterQuery::normalize('open', null, null, 1));
        $second = $register->snapshot($this->today(), TaskRegisterQuery::normalize('open', null, null, 2));
        $clamped = $register->snapshot($this->today(), TaskRegisterQuery::normalize('open', null, null, 99));

        self::assertSame(50, count($first->rows));
        self::assertSame(51, $first->rows[0]->actionItemId);
        self::assertSame(2, $first->rows[49]->actionItemId);
        self::assertSame(1, count($second->rows));
        self::assertSame(1, $second->rows[0]->actionItemId);
        self::assertSame(2, $clamped->page);
        self::assertSame(1, $clamped->rows[0]->actionItemId);
        self::assertSame($first->openCount, $second->openCount);
    }

    public function test_recording_can_change_status_without_changing_the_task(): void
    {
        $world = $this->mutationWorld();
        $record = $world['record'];
        $id = $world['id'];
        $before = $world['items']->find($id);
        self::assertNotNull($before);

        $record->setActionStatus($id, ActionStatus::Done, null);
        $done = $world['items']->find($id);
        self::assertNotNull($done);
        self::assertSame($id, $done->id());
        self::assertSame(ActionStatus::Done, $done->status());
        self::assertSame($before->task(), $done->task());
        self::assertSame($before->meetingId(), $done->meetingId());
        self::assertSame($before->agendaItemId(), $done->agendaItemId());
        self::assertSame($before->assigneePersonId(), $done->assigneePersonId());
        self::assertSame($before->dueOn()?->iso(), $done->dueOn()?->iso());

        $record->setActionStatus($id, ActionStatus::Done, null);
        self::assertSame(ActionStatus::Done, $world['items']->find($id)?->status());

        $record->setActionStatus($id, ActionStatus::Open, null);
        $reopened = $world['items']->find($id);
        self::assertNotNull($reopened);
        self::assertSame(ActionStatus::Open, $reopened->status());
        self::assertSame($before->task(), $reopened->task());
        self::assertSame($before->meetingId(), $reopened->meetingId());
        self::assertSame($before->agendaItemId(), $reopened->agendaItemId());
        self::assertSame($before->assigneePersonId(), $reopened->assigneePersonId());
        self::assertSame($before->dueOn()?->iso(), $reopened->dueOn()?->iso());
    }

    public function test_viewing_meetings_cannot_change_task_status(): void
    {
        $world = $this->mutationWorld([Capabilities::VIEW_INTERNAL_MEETINGS]);

        try {
            $world['record']->setActionStatus($world['id'], ActionStatus::Done, null);
            self::fail('Task status should require record_meeting.');
        } catch (NotAllowed $error) {
            self::assertSame(Capabilities::RECORD_MEETING, $error->getMessage());
        }

        self::assertSame(ActionStatus::Open, $world['items']->find($world['id'])?->status());
    }

    public function test_invalid_and_unknown_status_changes_are_rejected(): void
    {
        try {
            TaskRegisterQuery::statusChange('deleted');
            self::fail('deleted is not a task status.');
        } catch (InvalidArgumentException) {
        }

        $world = $this->mutationWorld();

        try {
            $world['record']->setActionStatus(9999, ActionStatus::Done, null);
            self::fail('An unknown task should be rejected.');
        } catch (\RuntimeException) {
        }

        self::assertSame(ActionStatus::Open, $world['items']->find($world['id'])?->status());
    }

    public function test_a_submitted_meeting_id_does_not_authorize_the_status_change(): void
    {
        $world = $this->mutationWorld();

        try {
            $world['record']->setActionStatus($world['id'], ActionStatus::Done, 9999);
            self::fail('A foreign meeting id should not move the task.');
        } catch (MeetingRuleException) {
        }

        self::assertSame(ActionStatus::Open, $world['items']->find($world['id'])?->status());
        $world['record']->setActionStatus($world['id'], ActionStatus::Done, null);
        self::assertSame(ActionStatus::Done, $world['items']->find($world['id'])?->status());
        self::assertSame($world['meetingId'], $world['items']->find($world['id'])?->meetingId());
    }

    public function test_the_tasks_screen_does_not_edit_or_delete(): void
    {
        $methods = get_class_methods(TaskRegister::class);
        self::assertNotContains('remove', $methods);
        self::assertNotContains('revised', $methods);
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/TasksPage.php');
        self::assertStringNotContainsString('removeActionItem', $source);
        self::assertStringNotContainsString('revised(', $source);
        self::assertStringNotContainsString('::remove(', $source);
        self::assertContains('setStatus', get_class_methods(TasksPage::class));
    }

    public function test_a_status_change_leaves_an_open_draft_unchanged_and_stale(): void
    {
        [$items, $drafts, $record, $meetingId, $taskId] = $this->minutesWorld(false);
        self::assertFalse($drafts->isStale($meetingId));
        $draft = $drafts->current($meetingId);
        self::assertNotNull($draft);
        self::assertSame(RevisionState::Draft, $draft->state());
        self::assertStringContainsString('Call painter', $draft->body());
        self::assertStringContainsString('öppen', $draft->body());
        $revisionId = $draft->id();
        $body = $draft->body();
        $payload = $draft->payload();
        $number = $draft->number();

        $record->setActionStatus($taskId, ActionStatus::Done, null);
        $after = $drafts->current($meetingId);
        self::assertNotNull($after);
        self::assertSame(ActionStatus::Done, $items->find($taskId)?->status());
        self::assertSame($revisionId, $after->id());
        self::assertSame($body, $after->body());
        self::assertSame($payload, $after->payload());
        self::assertSame($number, $after->number());
        self::assertTrue($drafts->isStale($meetingId));
        self::assertCount(1, $drafts->history($meetingId));
    }

    public function test_a_status_change_after_finalization_leaves_the_revision_unchanged(): void
    {
        [$items, $drafts, $record, $meetingId, $taskId] = $this->minutesWorld(true);
        $locked = $drafts->current($meetingId);
        self::assertNotNull($locked);
        self::assertSame(RevisionState::Finalized, $locked->state());
        self::assertStringContainsString('Call three roof contractors', $locked->body());
        $revisionId = $locked->id();
        $body = $locked->body();
        $payload = $locked->payload();
        $number = $locked->number();
        $visibility = $locked->visibility();
        $count = count($drafts->history($meetingId));

        $record->setActionStatus($taskId, ActionStatus::Done, null);
        $afterDone = $drafts->current($meetingId);
        self::assertNotNull($afterDone);
        self::assertSame(ActionStatus::Done, $items->find($taskId)?->status());
        self::assertSame($revisionId, $afterDone->id());
        self::assertSame($body, $afterDone->body());
        self::assertSame($payload, $afterDone->payload());
        self::assertSame($number, $afterDone->number());
        self::assertSame($visibility, $afterDone->visibility());
        self::assertCount($count, $drafts->history($meetingId));

        $record->setActionStatus($taskId, ActionStatus::Open, null);
        $afterOpen = $drafts->current($meetingId);
        self::assertNotNull($afterOpen);
        self::assertSame(ActionStatus::Open, $items->find($taskId)?->status());
        self::assertSame($revisionId, $afterOpen->id());
        self::assertSame($body, $afterOpen->body());
        self::assertSame($payload, $afterOpen->payload());
        self::assertSame($number, $afterOpen->number());
        self::assertSame($visibility, $afterOpen->visibility());
        self::assertCount($count, $drafts->history($meetingId));
    }

    /**
     * @return array{
     *     register: TaskRegister,
     *     hidden: TaskRegister,
     *     items: MemoryActionItemRepository,
     *     roof: ActionItem,
     *     plan: ActionItem,
     *     level: ActionItem,
     *     missingItem: ActionItem,
     *     missingMeeting: ActionItem,
     *     missingPerson: ActionItem,
     *     memorial: ActionItem,
     *     yesterday: ActionItem,
     *     todayDue: ActionItem,
     *     future: ActionItem,
     *     undated: ActionItem,
     *     doneOld: ActionItem,
     *     anna: Person,
     *     openOrder: list<int>,
     *     doneOrder: list<int>
     * }
     */
    private function sample(): array
    {
        $items = new MemoryActionItemRepository();
        $meetings = new MemoryMeetingRepository();
        $agenda = new MemoryAgendaRepository();
        $people = new MemoryPersonRepository();
        $anna = $people->add(new Person(null, 'Anna', 'Andersson', 'anna@example.test', PersonStatus::Known, null));
        $erik = $people->add(new Person(null, 'Erik', 'Memorial', 'erik@example.test', PersonStatus::Deceased, null));
        $board = $meetings->add(new Meeting(null, 1, 'Board meeting', MeetingMoment::fromLocal('2026-09-01 18:00'), '', MeetingStatus::Held));
        $older = $meetings->add(new Meeting(null, 1, 'Older meeting', MeetingMoment::fromLocal('2026-01-01 18:00'), '', MeetingStatus::Held));
        $newer = $meetings->add(new Meeting(null, 1, 'Newer meeting', MeetingMoment::fromLocal('2026-05-01 18:00'), '', MeetingStatus::Held));
        $progress = $meetings->add(new Meeting(null, 1, 'Working meeting', MeetingMoment::fromLocal('2026-09-20 18:00'), '', MeetingStatus::InProgress));
        $item = $agenda->add(new AgendaItem(null, (int) $board->id(), 3, 'Roof repairs', '§ 7'));
        $positionItem = $agenda->add(new AgendaItem(null, (int) $progress->id(), 2, 'Newsletter', ''));

        $add = static function (
            int $meetingId,
            ?int $agendaItemId,
            string $task,
            ?int $personId,
            ?string $dueOn,
            ActionStatus $status,
        ) use ($items): ActionItem {
            return $items->add(new ActionItem(
                null,
                $meetingId,
                $agendaItemId,
                $task,
                $personId,
                $dueOn === null ? null : AssociationDate::fromIso($dueOn),
                $status
            ));
        };

        $undatedOld = $add((int) $older->id(), null, 'Undated older', null, null, ActionStatus::Open);
        $overdueLater = $add((int) $board->id(), (int) $item->id(), 'Call three roof contractors', (int) $anna->id(), '2026-08-01', ActionStatus::Open);
        $future = $add((int) $progress->id(), (int) $positionItem->id(), 'Future due', (int) $anna->id(), '2026-12-01', ActionStatus::Open);
        $overdueOlderMeeting = $add((int) $older->id(), null, 'Overdue older meeting', null, '2026-07-01', ActionStatus::Open);
        $todayDue = $add((int) $board->id(), null, 'Due today', null, self::TODAY, ActionStatus::Open);
        $undatedNewer = $add((int) $newer->id(), null, 'Undated newer', null, null, ActionStatus::Open);
        $overdueNewerMeeting = $add((int) $newer->id(), null, 'Overdue newer meeting', null, '2026-07-01', ActionStatus::Open);
        $doneRecent = $add((int) $newer->id(), null, 'Done recent', (int) $erik->id(), '2020-01-01', ActionStatus::Done);
        $doneOld = $add((int) $older->id(), null, 'Done old', null, '2020-01-02', ActionStatus::Done);
        $doneSameDate = $add((int) $newer->id(), null, 'Done same date', null, null, ActionStatus::Done);
        $level = $add((int) $board->id(), null, 'Meeting level', null, null, ActionStatus::Open);
        $missingItem = $add((int) $board->id(), 50, 'Missing agenda', null, null, ActionStatus::Open);
        $missingMeeting = $add(80, null, 'Orphan task', null, null, ActionStatus::Open);
        $missingPerson = $add((int) $board->id(), null, 'Missing person', 4242, null, ActionStatus::Open);

        return [
            'register' => $this->register($items, $meetings, $agenda, $people, [Capabilities::VIEW_INTERNAL_MEETINGS]),
            'hidden' => $this->register($items, $meetings, $agenda, $people, [Capabilities::MANAGE_ASSOCIATION, Capabilities::ACCESS_ASSOCIATION]),
            'items' => $items,
            'roof' => $overdueLater,
            'plan' => $undatedOld,
            'level' => $level,
            'missingItem' => $missingItem,
            'missingMeeting' => $missingMeeting,
            'missingPerson' => $missingPerson,
            'memorial' => $doneRecent,
            'yesterday' => $overdueLater,
            'todayDue' => $todayDue,
            'future' => $future,
            'undated' => $undatedNewer,
            'doneOld' => $doneOld,
            'anna' => $anna,
            'openOrder' => [
                (int) $overdueNewerMeeting->id(),
                (int) $overdueOlderMeeting->id(),
                (int) $overdueLater->id(),
                (int) $todayDue->id(),
                (int) $future->id(),
                (int) $missingPerson->id(),
                (int) $missingItem->id(),
                (int) $level->id(),
                (int) $undatedNewer->id(),
                (int) $undatedOld->id(),
                (int) $missingMeeting->id(),
            ],
            'doneOrder' => [
                (int) $doneSameDate->id(),
                (int) $doneRecent->id(),
                (int) $doneOld->id(),
            ],
        ];
    }

    /**
     * @param list<string> $capabilities
     * @return array{record: MeetingRecord, items: MemoryActionItemRepository, id: int, meetingId: int}
     */
    private function mutationWorld(?array $capabilities = null): array
    {
        $capabilities ??= [Capabilities::RECORD_MEETING, Capabilities::VIEW_INTERNAL_MEETINGS];
        $items = new MemoryActionItemRepository();
        $meetings = new MemoryMeetingRepository();
        $agenda = new MemoryAgendaRepository();
        $meeting = $meetings->add(new Meeting(null, 1, 'Board meeting', MeetingMoment::fromLocal('2026-09-01 18:00'), '', MeetingStatus::Held));
        $item = $agenda->add(new AgendaItem(null, (int) $meeting->id(), 1, 'Roof repairs', '§ 7'));
        $task = $items->add(new ActionItem(
            null,
            (int) $meeting->id(),
            (int) $item->id(),
            'Call three roof contractors',
            null,
            AssociationDate::fromIso('2026-10-01'),
            ActionStatus::Open
        ));
        $record = new MeetingRecord(
            $meetings,
            $agenda,
            new MemoryPersonRepository(),
            new MemoryNoteRepository(),
            new MemoryDecisionRepository(),
            $items,
            $this->authorizer($capabilities),
            $this->transaction()
        );

        return [
            'record' => $record,
            'items' => $items,
            'id' => (int) $task->id(),
            'meetingId' => (int) $meeting->id(),
        ];
    }

    /**
     * @return array{0: MemoryActionItemRepository, 1: MinutesDrafts, 2: MeetingRecord, 3: int, 4: int}
     */
    private function minutesWorld(bool $finalize): array
    {
        $types = new MemoryMeetingTypeRepository();
        $types->add(new MeetingType(null, 'board_meeting', 'Board meeting', 10));
        $meetings = new MemoryMeetingRepository();
        $people = new MemoryPersonRepository();
        $agenda = new MemoryAgendaRepository();
        $items = new MemoryActionItemRepository();
        $authorizer = $this->authorizer([
            Capabilities::MANAGE_MEETINGS,
            Capabilities::RECORD_MEETING,
            Capabilities::VIEW_INTERNAL_MEETINGS,
            Capabilities::FINALIZE_MINUTES,
        ]);
        $transaction = $this->transaction();
        $meetingService = new MeetingService($types, $meetings, new MeetingLifecycle(), $authorizer, $transaction);
        $drafts = new MinutesDrafts(
            $meetings,
            $people,
            new MemoryParticipantRepository(),
            $agenda,
            new MemoryNoteRepository(),
            new MemoryDecisionRepository(),
            $items,
            new MemoryMinutesRepository(),
            new MinutesComposer(),
            new MinutesLifecycle(),
            new AgendaOrder(),
            $authorizer,
            $transaction
        );
        $record = new MeetingRecord(
            $meetings,
            $agenda,
            $people,
            new MemoryNoteRepository(),
            new MemoryDecisionRepository(),
            $items,
            $authorizer,
            $transaction
        );
        $meetingId = $meetingService->schedule(1, 'Board meeting', MeetingMoment::fromLocal('2026-09-01 18:00'), '');
        $meetingService->markHeld($meetingId);
        $itemId = (int) $agenda->add(new AgendaItem(null, $meetingId, 1, 'Roof repairs', '§ 7'))->id();
        $task = $items->add(new ActionItem(
            null,
            $meetingId,
            $itemId,
            $finalize ? 'Call three roof contractors' : 'Call painter',
            null,
            null,
            ActionStatus::Open
        ));
        $draftId = $drafts->create($meetingId);

        if ($finalize) {
            $drafts->submit($draftId);
            $drafts->finalize($draftId);
        }

        return [$items, $drafts, $record, $meetingId, (int) $task->id()];
    }

    /**
     * @param list<string> $capabilities
     */
    private function register(
        MemoryActionItemRepository $items,
        MemoryMeetingRepository $meetings,
        MemoryAgendaRepository $agenda,
        MemoryPersonRepository $people,
        array $capabilities,
    ): TaskRegister {
        return new TaskRegister($items, $meetings, $agenda, $people, $this->authorizer($capabilities));
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

    private function today(): AssociationDate
    {
        return AssociationDate::fromIso(self::TODAY);
    }

    private function row(TaskRegisterSnapshot $snapshot, int $id): \Foreningssystem\Application\Task\TaskRegisterRow
    {
        foreach ($snapshot->rows as $row) {
            if ($row->actionItemId === $id) {
                return $row;
            }
        }

        self::fail('Task ' . $id . ' was missing from the register.');
    }
}
