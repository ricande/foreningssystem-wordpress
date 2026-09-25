<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Task;

use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\MeetingStatus;

final class TaskRegisterRow
{
    public function __construct(
        public readonly int $actionItemId,
        public readonly string $task,
        public readonly ActionStatus $status,
        public readonly ?int $assigneePersonId,
        public readonly ?string $assigneeName,
        public readonly AssigneePresentation $assigneeState,
        public readonly ?string $dueOn,
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
