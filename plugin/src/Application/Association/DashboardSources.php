<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Association;

use Foreningssystem\Application\Board\BoardSeat;
use Foreningssystem\Application\Meeting\ActionItemRow;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Membership\AssociationDate;

final class DashboardSources
{
    /**
     * @param list<BoardSeat> $boardSeats
     * @param list<Meeting> $meetings
     * @param list<MinutesRevision> $revisions
     * @param list<ActionItemRow> $openActions
     * @param list<array{id: int, title: string, visibility: string}> $documents
     */
    public function __construct(
        public readonly bool $canViewMembers,
        public readonly bool $canViewMeetings,
        public readonly bool $canManageMeetings,
        public readonly bool $canViewDocuments,
        public readonly bool $canManageAssociation,
        public readonly bool $canEditMembers,
        public readonly bool $canManageBoard,
        public readonly bool $canManageDocuments,
        public readonly string $associationName,
        public readonly AssociationDate $today,
        public readonly MeetingMoment $now,
        public readonly int $activeMembers = 0,
        public readonly int $activeMemberships = 0,
        public readonly int $currentBoard = 0,
        public readonly array $boardSeats = [],
        public readonly array $meetings = [],
        public readonly array $revisions = [],
        public readonly int $openDecisions = 0,
        public readonly array $openActions = [],
        public readonly array $documents = [],
    ) {
    }
}
