<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Decision;

use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\MeetingStatus;

final class DecisionRegisterRow
{
    public function __construct(
        public readonly int $decisionId,
        public readonly string $wording,
        public readonly DecisionFollowUp $followUp,
        public readonly ?int $responsiblePersonId,
        public readonly ?string $responsibleName,
        public readonly ResponsiblePresentation $responsibleState,
        public readonly ?string $deadline,
        public readonly bool $overdue,
        public readonly int $meetingId,
        public readonly bool $meetingAvailable,
        public readonly ?string $meetingTitle,
        public readonly ?string $meetingDate,
        public readonly ?MeetingStatus $meetingStatus,
        public readonly ?int $agendaItemId,
        public readonly AgendaPresentation $agendaState,
        public readonly ?string $agendaNumber,
        public readonly ?string $agendaTitle,
    ) {
    }
}
