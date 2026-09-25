<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Task;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\ActionItem;
use Foreningssystem\Domain\Meeting\ActionItemRepository;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\AgendaItem;
use Foreningssystem\Domain\Meeting\AgendaRepository;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingRepository;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;

final class TaskRegister
{
    public function __construct(
        private readonly ActionItemRepository $actionItems,
        private readonly MeetingRepository $meetings,
        private readonly AgendaRepository $agenda,
        private readonly PersonRepository $people,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function snapshot(AssociationDate $today, TaskRegisterQuery $query): TaskRegisterSnapshot
    {
        if (! $this->authorizer->allows(Capabilities::VIEW_INTERNAL_MEETINGS)) {
            throw new NotAllowed(Capabilities::VIEW_INTERNAL_MEETINGS);
        }

        $items = $this->actionItems->all();
        $meetings = $this->meetingsById();
        $people = $this->peopleById();
        $agenda = $this->agendaById($items);
        $rows = [];

        foreach ($items as $item) {
            $id = $item->id();

            if ($id === null) {
                continue;
            }

            $rows[] = $this->row($item, $id, $today, $meetings, $people, $agenda);
        }

        $openCount = 0;
        $overdueCount = 0;
        $doneCount = 0;

        foreach ($rows as $row) {
            if ($row->status === ActionStatus::Open) {
                $openCount++;

                if ($row->overdue) {
                    $overdueCount++;
                }
            } else {
                $doneCount++;
            }
        }

        $matched = array_values(array_filter(
            $rows,
            static fn (TaskRegisterRow $row): bool => self::matches($row, $query)
        ));
        usort($matched, static fn (TaskRegisterRow $left, TaskRegisterRow $right): int => self::compare($left, $right));

        $pages = max(1, (int) ceil(count($matched) / TaskRegisterQuery::PAGE_SIZE));
        $page = min($query->page, $pages);
        $offset = ($page - 1) * TaskRegisterQuery::PAGE_SIZE;

        return new TaskRegisterSnapshot(
            $openCount,
            $overdueCount,
            $doneCount,
            count($matched),
            $page,
            $pages,
            array_values(array_slice($matched, $offset, TaskRegisterQuery::PAGE_SIZE)),
            $this->choices($rows),
            $query
        );
    }

    /**
     * @param array<int, Meeting> $meetings
     * @param array<int, Person> $people
     * @param array<int, AgendaItem> $agenda
     */
    private function row(
        ActionItem $item,
        int $id,
        AssociationDate $today,
        array $meetings,
        array $people,
        array $agenda,
    ): TaskRegisterRow {
        $meeting = $meetings[$item->meetingId()] ?? null;
        $personId = $item->assigneePersonId();
        $person = $personId !== null ? ($people[$personId] ?? null) : null;
        $itemId = $item->agendaItemId();
        $agendaItem = $itemId !== null ? ($agenda[$itemId] ?? null) : null;

        if ($agendaItem !== null && $agendaItem->meetingId() !== $item->meetingId()) {
            $agendaItem = null;
        }

        if ($personId === null) {
            $assigneeState = AssigneePresentation::Unassigned;
            $assigneeName = null;
        } elseif (! $person instanceof Person) {
            $assigneeState = AssigneePresentation::Unavailable;
            $assigneeName = null;
        } else {
            $assigneeState = AssigneePresentation::Assigned;
            $assigneeName = $person->firstName() . ' ' . $person->lastName();
        }

        if ($itemId === null) {
            $agendaState = AgendaPresentation::None;
        } elseif (! $agendaItem instanceof AgendaItem) {
            $agendaState = AgendaPresentation::Unavailable;
        } else {
            $agendaState = AgendaPresentation::Item;
        }

        return new TaskRegisterRow(
            $id,
            $item->task(),
            $item->status(),
            $personId,
            $assigneeName,
            $assigneeState,
            $item->dueOn()?->iso(),
            $item->isOverdue($today),
            $item->meetingId(),
            $meeting instanceof Meeting,
            $meeting instanceof Meeting ? $meeting->title() : null,
            $meeting instanceof Meeting ? $meeting->startsAt()->date() : null,
            $meeting instanceof Meeting ? $meeting->status() : null,
            $itemId,
            $agendaState,
            $agendaItem instanceof AgendaItem ? $agendaItem->displayNumber() : null,
            $agendaItem instanceof AgendaItem ? $agendaItem->title() : null,
        );
    }

    /**
     * @return array<int, Meeting>
     */
    private function meetingsById(): array
    {
        $meetings = [];

        foreach ($this->meetings->all() as $meeting) {
            $id = $meeting->id();

            if ($id !== null) {
                $meetings[$id] = $meeting;
            }
        }

        return $meetings;
    }

    /**
     * @return array<int, Person>
     */
    private function peopleById(): array
    {
        $people = [];

        foreach ($this->people->all() as $person) {
            $id = $person->id();

            if ($id !== null) {
                $people[$id] = $person;
            }
        }

        return $people;
    }

    /**
     * @param list<ActionItem> $items
     * @return array<int, AgendaItem>
     */
    private function agendaById(array $items): array
    {
        $meetingIds = [];

        foreach ($items as $item) {
            $meetingIds[$item->meetingId()] = true;
        }

        $agenda = [];

        foreach (array_keys($meetingIds) as $meetingId) {
            foreach ($this->agenda->forMeeting($meetingId) as $item) {
                $id = $item->id();

                if ($id !== null) {
                    $agenda[$id] = $item;
                }
            }
        }

        return $agenda;
    }

    private static function matches(TaskRegisterRow $row, TaskRegisterQuery $query): bool
    {
        if ($query->status === TaskRegisterQuery::OPEN && $row->status !== ActionStatus::Open) {
            return false;
        }

        if ($query->status === TaskRegisterQuery::DONE && $row->status !== ActionStatus::Done) {
            return false;
        }

        if ($query->assignee === TaskRegisterQuery::UNASSIGNED && $row->assigneeState !== AssigneePresentation::Unassigned) {
            return false;
        }

        if ($query->assignee !== TaskRegisterQuery::ALL && $query->assignee !== TaskRegisterQuery::UNASSIGNED) {
            if ($row->assigneePersonId !== (int) $query->assignee) {
                return false;
            }
        }

        if ($query->urgency === TaskRegisterQuery::OVERDUE && ! $row->overdue) {
            return false;
        }

        return true;
    }

    private static function compare(TaskRegisterRow $left, TaskRegisterRow $right): int
    {
        $leftOpen = $left->status === ActionStatus::Open;
        $rightOpen = $right->status === ActionStatus::Open;

        if ($leftOpen !== $rightOpen) {
            return $leftOpen ? -1 : 1;
        }

        if ($leftOpen) {
            $rank = self::openRank($left) <=> self::openRank($right);

            if ($rank !== 0) {
                return $rank;
            }

            if (self::openRank($left) === 2) {
                return self::compareDatesDesc($left->meetingDate, $right->meetingDate)
                    ?: ($right->actionItemId <=> $left->actionItemId);
            }

            return ($left->dueOn <=> $right->dueOn)
                ?: self::compareDatesDesc($left->meetingDate, $right->meetingDate)
                ?: ($left->actionItemId <=> $right->actionItemId);
        }

        return self::compareDatesDesc($left->meetingDate, $right->meetingDate)
            ?: ($right->actionItemId <=> $left->actionItemId);
    }

    private static function openRank(TaskRegisterRow $row): int
    {
        if ($row->overdue) {
            return 0;
        }

        return $row->dueOn !== null ? 1 : 2;
    }

    private static function compareDatesDesc(?string $left, ?string $right): int
    {
        return ($right ?? '') <=> ($left ?? '');
    }

    /**
     * @param list<TaskRegisterRow> $rows
     * @return list<array{id: int, name: string}>
     */
    private function choices(array $rows): array
    {
        $choices = [];

        foreach ($rows as $row) {
            if ($row->assigneeState !== AssigneePresentation::Assigned || $row->assigneePersonId === null || $row->assigneeName === null) {
                continue;
            }

            $choices[$row->assigneePersonId] = [
                'id' => $row->assigneePersonId,
                'name' => $row->assigneeName,
            ];
        }

        $choices = array_values($choices);
        usort($choices, static fn (array $left, array $right): int => [$left['name'], $left['id']] <=> [$right['name'], $right['id']]);

        return $choices;
    }
}
