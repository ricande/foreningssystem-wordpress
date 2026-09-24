<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Association\WorkOverview;
use Foreningssystem\Domain\Meeting\ActionItem;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Membership\AssociationDate;
use PHPUnit\Framework\TestCase;

final class WorkOverviewTest extends TestCase
{
    public function test_the_overview_shows_the_next_meeting_the_last_held_one_and_overdue_tasks(): void
    {
        $overview = new WorkOverview();
        $now = MeetingMoment::fromLocal('2026-09-24 08:00');
        $meetings = [
            $this->meeting(1, 'Gammalt planerat', '2020-01-01 18:00', MeetingStatus::Planned),
            $this->meeting(2, 'Hållet i våras', '2026-03-01 18:00', MeetingStatus::Held),
            $this->meeting(3, 'Hållet senare', '2026-06-01 18:00', MeetingStatus::Held),
            $this->meeting(4, 'Pågår ikväll', '2026-09-24 18:00', MeetingStatus::InProgress),
            $this->meeting(5, 'Senare planerat', '2026-12-01 18:00', MeetingStatus::Planned),
        ];
        $items = [
            new ActionItem(1, 3, null, 'Redan klar', null, AssociationDate::fromIso('2026-01-01'), ActionStatus::Done),
            new ActionItem(2, 3, null, 'Förfallen', null, AssociationDate::fromIso('2026-09-23'), ActionStatus::Open),
            new ActionItem(3, 3, null, 'Förfaller idag', null, AssociationDate::fromIso('2026-09-24'), ActionStatus::Open),
            new ActionItem(4, 3, null, 'Utan datum', null, null, ActionStatus::Open),
            new ActionItem(5, 3, null, 'Äldre förfallen', null, AssociationDate::fromIso('2026-01-02'), ActionStatus::Open),
        ];

        self::assertSame('Pågår ikväll', $overview->nextPlanned($meetings, $now)?->title());
        self::assertSame('Hållet senare', $overview->lastHeld($meetings)?->title());
        self::assertSame(
            ['Äldre förfallen', 'Förfallen'],
            array_map(static fn (ActionItem $item): string => $item->task(), $overview->overdue($items, AssociationDate::fromIso('2026-09-24')))
        );
    }

    private function meeting(int $id, string $title, string $startsAt, MeetingStatus $status): Meeting
    {
        return new Meeting($id, 1, $title, MeetingMoment::fromLocal($startsAt), '', $status);
    }
}
