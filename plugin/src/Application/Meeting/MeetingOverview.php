<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingStatus;

final class MeetingOverview
{
    /**
     * In-progress meetings first, then planned meetings by nearest date, then held meetings newest first.
     *
     * @param list<Meeting> $meetings
     * @return array{in_progress: list<Meeting>, planned: list<Meeting>, held: list<Meeting>}
     */
    public function sections(array $meetings): array
    {
        $inProgress = [];
        $planned = [];
        $held = [];

        foreach ($meetings as $meeting) {
            match ($meeting->status()) {
                MeetingStatus::InProgress => $inProgress[] = $meeting,
                MeetingStatus::Planned => $planned[] = $meeting,
                MeetingStatus::Held => $held[] = $meeting,
            };
        }

        $soonest = static fn (Meeting $left, Meeting $right): int => self::compare($left, $right, false);
        $newest = static fn (Meeting $left, Meeting $right): int => self::compare($left, $right, true);
        usort($inProgress, $soonest);
        usort($planned, $soonest);
        usort($held, $newest);

        return [
            'in_progress' => $inProgress,
            'planned' => $planned,
            'held' => $held,
        ];
    }

    private static function compare(Meeting $left, Meeting $right, bool $newestFirst): int
    {
        $byTime = $left->startsAt()->local() <=> $right->startsAt()->local();

        if ($newestFirst) {
            $byTime = -$byTime;
        }

        if ($byTime !== 0) {
            return $byTime;
        }

        return ((int) $left->id()) <=> ((int) $right->id());
    }
}
