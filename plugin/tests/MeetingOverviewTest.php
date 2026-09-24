<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Meeting\MeetingOverview;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use PHPUnit\Framework\TestCase;

final class MeetingOverviewTest extends TestCase
{
    public function test_sections_put_in_progress_first_then_nearest_plans_then_newest_held(): void
    {
        $overview = new MeetingOverview();
        $sections = $overview->sections([
            new Meeting(4, 1, 'Held older', MeetingMoment::fromLocal('2026-01-10 18:00'), 'Hall', MeetingStatus::Held),
            new Meeting(2, 1, 'Planned later', MeetingMoment::fromLocal('2026-12-01 18:00'), 'Hall', MeetingStatus::Planned),
            new Meeting(5, 1, 'Held newer', MeetingMoment::fromLocal('2026-08-01 18:00'), 'Hall', MeetingStatus::Held),
            new Meeting(3, 1, 'Planned sooner', MeetingMoment::fromLocal('2026-10-02 18:00'), 'Hall', MeetingStatus::Planned),
            new Meeting(1, 1, 'In progress', MeetingMoment::fromLocal('2026-09-24 18:00'), 'Hall', MeetingStatus::InProgress),
        ]);

        self::assertSame(['In progress'], $this->titles($sections['in_progress']));
        self::assertSame(['Planned sooner', 'Planned later'], $this->titles($sections['planned']));
        self::assertSame(['Held newer', 'Held older'], $this->titles($sections['held']));
    }

    /**
     * @param list<Meeting> $meetings
     * @return list<string>
     */
    private function titles(array $meetings): array
    {
        return array_map(static fn (Meeting $meeting): string => $meeting->title(), $meetings);
    }
}
