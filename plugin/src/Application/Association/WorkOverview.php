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

            if ($next === null || $meeting->startsAt()->local() < $next->startsAt()->local()) {
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

            if ($last === null || $meeting->startsAt()->local() > $last->startsAt()->local()) {
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
}
