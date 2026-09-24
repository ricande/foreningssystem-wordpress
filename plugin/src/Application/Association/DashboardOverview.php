<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Association;

use Foreningssystem\Application\Meeting\ActionItemRow;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\RevisionState;

final class DashboardOverview
{
    public function __construct(private readonly WorkOverview $work = new WorkOverview())
    {
    }

    public function snapshot(DashboardSources $sources): DashboardSnapshot
    {
        $currentBoard = [];
        $upcomingBoard = [];

        if ($sources->canViewMembers) {
            foreach ($sources->boardSeats as $seat) {
                if ($seat->state() === 'current') {
                    $currentBoard[] = ['role' => $seat->roleName(), 'name' => $seat->personName()];
                }

                if ($seat->state() === 'upcoming') {
                    $upcomingBoard[] = [
                        'role' => $seat->roleName(),
                        'name' => $seat->personName(),
                        'startsOn' => $seat->startedOn(),
                    ];
                }
            }
        }

        $inProgress = null;
        $next = null;
        $latestHeld = null;
        $latestHeldMinutes = null;
        $latestFinalized = null;
        $minutesWork = [];
        $attention = [];

        if ($sources->canViewMeetings) {
            $running = $this->inProgressMeetings($sources->meetings);
            $inProgress = $running[0] ?? null;
            $next = $this->nextPlanned($sources->meetings, $sources->now);
            $latestHeld = $this->work->lastHeld($sources->meetings);
            $byMeeting = $this->currentRevisions($sources->revisions);

            if ($latestHeld !== null && $latestHeld->id() !== null) {
                $latestHeldMinutes = $this->minutesState($byMeeting[$latestHeld->id()] ?? null);
            }

            $latestFinalized = $this->latestFinalized($sources->meetings, $sources->revisions);

            foreach ($this->heldNewest($sources->meetings) as $meeting) {
                $meetingId = (int) $meeting->id();
                $revision = $byMeeting[$meetingId] ?? null;
                $state = $this->minutesState($revision);

                if ($state === 'finalized') {
                    continue;
                }

                $minutesWork[] = [
                    'title' => $meeting->title(),
                    'detail' => $meeting->startsAt()->date(),
                    'meetingId' => $meetingId,
                    'state' => $state,
                ];
            }

            foreach ($running as $meeting) {
                $attention[] = [
                    'kind' => 'in_progress',
                    'title' => $meeting->title(),
                    'detail' => $meeting->startsAt()->date() . ' ' . $meeting->startsAt()->time(),
                    'meetingId' => $meeting->id(),
                ];
            }
        }

        $openTasks = null;

        if ($sources->canViewMeetings) {
            $openTasks = 0;

            foreach ($sources->openActions as $row) {
                if ($row->item()->status() === ActionStatus::Open) {
                    $openTasks++;
                }
            }
        }
        $overdueTasks = [];

        if ($sources->canViewMeetings) {
            $items = [];

            foreach ($sources->openActions as $row) {
                $items[] = $row->item();
            }

            foreach ($this->work->overdue($items, $sources->today) as $item) {
                $row = $this->rowFor($sources->openActions, (int) $item->id());
                $overdueTasks[] = [
                    'task' => $item->task(),
                    'assignee' => $row?->assigneeName(),
                    'due' => (string) $item->dueOn()?->iso(),
                    'meetingId' => $item->meetingId(),
                ];
            }

            if ($overdueTasks !== []) {
                $attention[] = [
                    'kind' => 'overdue',
                    'title' => (string) count($overdueTasks),
                    'detail' => '',
                    'meetingId' => $overdueTasks[0]['meetingId'],
                ];
            }

            foreach ($minutesWork as $work) {
                $attention[] = [
                    'kind' => 'minutes',
                    'title' => $work['title'],
                    'detail' => $work['state'],
                    'meetingId' => $work['meetingId'],
                ];
            }
        }

        if ($sources->canViewMembers) {
            foreach ($upcomingBoard as $change) {
                $attention[] = [
                    'kind' => 'board_change',
                    'title' => $change['role'],
                    'detail' => $change['name'] . '|' . $change['startsOn'],
                    'meetingId' => null,
                ];
            }
        }

        if ($next !== null) {
            $attention[] = [
                'kind' => 'next_meeting',
                'title' => $next->title(),
                'detail' => $next->startsAt()->date() . ' ' . $next->startsAt()->time(),
                'meetingId' => $next->id(),
            ];
        }

        $documents = [];

        if ($sources->canViewDocuments) {
            $documents = $sources->documents;
            // Association documents have no created timestamp. Descending id is the v1 chronology.
            usort($documents, static fn (array $left, array $right): int => $right['id'] <=> $left['id']);
            $documents = array_slice($documents, 0, 5);
        }

        $hasWork = ($sources->canViewMembers && ($sources->activeMembers > 0 || $sources->activeMemberships > 0 || $currentBoard !== [] || $upcomingBoard !== []))
            || ($sources->canViewMeetings && $sources->meetings !== [])
            || $documents !== [];
        $setup = $hasWork ? [] : $this->setup($sources);

        return new DashboardSnapshot(
            $sources->associationName,
            $sources->canViewMembers,
            $sources->canViewMeetings,
            $sources->canViewDocuments,
            $sources->canManageMeetings,
            $sources->canViewMembers ? $sources->activeMembers : 0,
            $sources->canViewMembers ? $sources->activeMemberships : 0,
            $sources->canViewMembers ? $sources->currentBoard : 0,
            $currentBoard,
            $upcomingBoard,
            $attention,
            $inProgress,
            $next,
            $latestHeld,
            $latestHeldMinutes,
            $latestFinalized,
            $minutesWork,
            $sources->canViewMeetings ? $sources->openDecisions : null,
            $openTasks,
            // Oldest overdue tasks first. The list is bounded so the page stays short.
            array_slice($overdueTasks, 0, 8),
            $documents,
            $setup,
            $sources->associationName === '' && $sources->canManageAssociation,
            ! $hasWork,
        );
    }

    /**
     * @param list<Meeting> $meetings
     * @return list<Meeting>
     */
    private function inProgressMeetings(array $meetings): array
    {
        $running = [];

        foreach ($meetings as $meeting) {
            if ($meeting->status() === MeetingStatus::InProgress) {
                $running[] = $meeting;
            }
        }

        usort($running, WorkOverview::compareMeetingAscending(...));

        return $running;
    }

    /**
     * @param list<Meeting> $meetings
     */
    private function nextPlanned(array $meetings, \Foreningssystem\Domain\Meeting\MeetingMoment $now): ?Meeting
    {
        $chosen = null;

        foreach ($meetings as $meeting) {
            if ($meeting->status() !== MeetingStatus::Planned || $meeting->startsAt()->local() < $now->local()) {
                continue;
            }

            if ($chosen === null || WorkOverview::compareMeetingAscending($meeting, $chosen) < 0) {
                $chosen = $meeting;
            }
        }

        return $chosen;
    }

    /**
     * @param list<MinutesRevision> $revisions
     * @return array<int, MinutesRevision>
     */
    private function currentRevisions(array $revisions): array
    {
        $current = [];

        foreach ($revisions as $revision) {
            $existing = $current[$revision->meetingId()] ?? null;

            if ($existing === null || $revision->number() > $existing->number()) {
                $current[$revision->meetingId()] = $revision;
            }
        }

        return $current;
    }

    private function minutesState(?MinutesRevision $revision): string
    {
        if (! $revision instanceof MinutesRevision || $revision->state() !== RevisionState::Finalized || $revision->supersededBy() !== null) {
            if (! $revision instanceof MinutesRevision) {
                return 'none';
            }

            return match ($revision->state()) {
                RevisionState::Draft => 'draft',
                RevisionState::UnderAdjustment => 'adjustment',
                RevisionState::Finalized => 'finalized',
            };
        }

        return 'finalized';
    }

    /**
     * @param list<Meeting> $meetings
     * @param list<MinutesRevision> $revisions
     * @return ?array{title: string, meetingId: int, number: int}
     */
    private function latestFinalized(array $meetings, array $revisions): ?array
    {
        $meetingsById = [];

        foreach ($meetings as $meeting) {
            if ($meeting->id() !== null) {
                $meetingsById[$meeting->id()] = $meeting;
            }
        }

        $chosen = null;
        $chosenMeeting = null;

        foreach ($revisions as $revision) {
            if ($revision->state() !== RevisionState::Finalized || $revision->supersededBy() !== null) {
                continue;
            }

            $meeting = $meetingsById[$revision->meetingId()] ?? null;

            if (! $meeting instanceof Meeting) {
                continue;
            }

            if ($chosen === null || $chosenMeeting === null || $this->isLaterFinalized($meeting, $revision, $chosenMeeting, $chosen)) {
                $chosen = $revision;
                $chosenMeeting = $meeting;
            }
        }

        if ($chosen === null || $chosenMeeting === null) {
            return null;
        }

        return [
            'title' => $chosenMeeting->title(),
            'meetingId' => (int) $chosenMeeting->id(),
            'number' => $chosen->number(),
        ];
    }

    private function isLaterFinalized(Meeting $candidateMeeting, MinutesRevision $candidate, Meeting $currentMeeting, MinutesRevision $current): bool
    {
        $byMeeting = WorkOverview::compareMeetingDescending($candidateMeeting, $currentMeeting);

        if ($byMeeting !== 0) {
            return $byMeeting < 0;
        }

        return $candidate->number() > $current->number();
    }

    /**
     * @param list<Meeting> $meetings
     * @return list<Meeting>
     */
    private function heldNewest(array $meetings): array
    {
        $held = [];

        foreach ($meetings as $meeting) {
            if ($meeting->status() === MeetingStatus::Held) {
                $held[] = $meeting;
            }
        }

        usort($held, WorkOverview::compareMeetingDescending(...));

        return $held;
    }

    /**
     * @param list<ActionItemRow> $rows
     */
    private function rowFor(array $rows, int $id): ?ActionItemRow
    {
        foreach ($rows as $row) {
            if ($row->item()->id() === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function setup(DashboardSources $sources): array
    {
        $steps = [];

        if ($sources->canEditMembers) {
            $steps[] = 'members';
        }

        if ($sources->canManageBoard) {
            $steps[] = 'board';
        }

        if ($sources->canManageMeetings) {
            $steps[] = 'meeting';
        }

        if ($sources->canManageDocuments) {
            $steps[] = 'documents';
        }

        return $steps;
    }
}
