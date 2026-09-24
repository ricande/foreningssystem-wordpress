<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Association;

use Foreningssystem\Domain\Meeting\Meeting;

final class DashboardSnapshot
{
    /**
     * @param list<array{kind: string, title: string, detail: string, meetingId: ?int}> $attention
     * @param list<array{role: string, name: string}> $currentBoard
     * @param list<array{role: string, name: string, startsOn: string}> $upcomingBoard
     * @param list<array{title: string, detail: string, meetingId: int, state: string}> $minutesWork
     * @param list<array{task: string, assignee: ?string, due: string, meetingId: int}> $overdueTasks
     * @param list<array{id: int, title: string, visibility: string}> $documents
     * @param list<string> $setup
     */
    public function __construct(
        public readonly string $associationName,
        public readonly bool $showMembers,
        public readonly bool $showMeetings,
        public readonly bool $showDocuments,
        public readonly bool $canManageMeetings,
        public readonly int $activeMembers,
        public readonly int $activeMemberships,
        public readonly int $currentBoardCount,
        public readonly array $currentBoard,
        public readonly array $upcomingBoard,
        public readonly array $attention,
        public readonly ?Meeting $inProgress,
        public readonly ?Meeting $nextMeeting,
        public readonly ?Meeting $latestHeld,
        public readonly ?string $latestHeldMinutes,
        public readonly ?array $latestFinalized,
        public readonly array $minutesWork,
        public readonly ?int $openDecisions,
        public readonly ?int $openTasks,
        public readonly array $overdueTasks,
        public readonly array $documents,
        public readonly array $setup,
        public readonly bool $needsProfile,
        public readonly bool $isEmpty,
    ) {
    }
}
