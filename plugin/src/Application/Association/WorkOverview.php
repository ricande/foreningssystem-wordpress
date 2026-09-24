<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Association;

use Foreningssystem\Domain\Meeting\ActionItem;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Membership\AssociationDate;

final class WorkOverview
{
    /**
     * @param list<Meeting> $meetings
     */
    public function nextPlanned(array $meetings, MeetingMoment $now): ?Meeting
    {
        $next = null;

        foreach ($meetings as $meeting) {
            if ($meeting->status() !== MeetingStatus::Planned && $meeting->status() !== MeetingStatus::InProgress) {
                continue;
            }

            if ($meeting->startsAt()->local() < $now->local()) {
                continue;
            }

            if ($next === null || self::compareMeetingAscending($meeting, $next) < 0) {
                $next = $meeting;
            }
        }

        return $next;
    }

    /**
     * @param list<Meeting> $meetings
     */
    public function lastHeld(array $meetings): ?Meeting
    {
        $last = null;

        foreach ($meetings as $meeting) {
            if ($meeting->status() !== MeetingStatus::Held) {
                continue;
            }

            if ($last === null || self::compareMeetingDescending($meeting, $last) < 0) {
                $last = $meeting;
            }
        }

        return $last;
    }

    /**
     * @param list<ActionItem> $items
     * @return list<ActionItem>
     */
    public function overdue(array $items, AssociationDate $today): array
    {
        $overdue = [];

        foreach ($items as $item) {
            $dueOn = $item->dueOn();

            if ($item->status() !== ActionStatus::Open || ! $dueOn instanceof AssociationDate || ! $dueOn->isBefore($today)) {
                continue;
            }

            $overdue[] = $item;
        }

        usort($overdue, static function (ActionItem $left, ActionItem $right): int {
            $byDate = ($left->dueOn()?->iso() ?? '') <=> ($right->dueOn()?->iso() ?? '');

            return $byDate !== 0 ? $byDate : ((int) $left->id()) <=> ((int) $right->id());
        });

        return $overdue;
    }

    /**
     * Earlier start first. The same start uses the lower meeting id.
     */
    public static function compareMeetingAscending(Meeting $left, Meeting $right): int
    {
        $byTime = $left->startsAt()->local() <=> $right->startsAt()->local();

        return $byTime !== 0 ? $byTime : self::meetingId($left) <=> self::meetingId($right);
    }

    /**
     * Later start first. The same start uses the higher meeting id.
     */
    public static function compareMeetingDescending(Meeting $left, Meeting $right): int
    {
        return self::compareMeetingAscending($right, $left);
    }

    private static function meetingId(Meeting $meeting): int
    {
        return (int) ($meeting->id() ?? 0);
    }
}
