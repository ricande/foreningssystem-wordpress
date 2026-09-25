<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

use Foreningssystem\Domain\Membership\AssociationDate;
use InvalidArgumentException;

final class ActionItem
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $meetingId,
        private readonly ?int $agendaItemId,
        string $task,
        private readonly ?int $assigneePersonId,
        private readonly ?AssociationDate $dueOn,
        private readonly ActionStatus $status,
    ) {
        $this->task = trim($task);

        if ($this->meetingId < 1 || ($this->agendaItemId !== null && $this->agendaItemId < 1)) {
            throw new InvalidArgumentException('An action item belongs to a saved meeting.');
        }

        if ($this->assigneePersonId !== null && $this->assigneePersonId < 1) {
            throw new InvalidArgumentException('An assignee must be a saved person.');
        }

        if ($this->task === '' || strlen($this->task) > 4000) {
            throw new InvalidArgumentException('An action item needs a task.');
        }
    }

    private readonly string $task;

    public function id(): ?int
    {
        return $this->id;
    }

    public function meetingId(): int
    {
        return $this->meetingId;
    }

    public function agendaItemId(): ?int
    {
        return $this->agendaItemId;
    }

    public function task(): string
    {
        return $this->task;
    }

    public function assigneePersonId(): ?int
    {
        return $this->assigneePersonId;
    }

    public function dueOn(): ?AssociationDate
    {
        return $this->dueOn;
    }

    public function status(): ActionStatus
    {
        return $this->status;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->meetingId, $this->agendaItemId, $this->task, $this->assigneePersonId, $this->dueOn, $this->status);
    }

    public function revised(string $task, ?int $assigneePersonId, ?AssociationDate $dueOn): self
    {
        return new self($this->id, $this->meetingId, $this->agendaItemId, $task, $assigneePersonId, $dueOn, $this->status);
    }

    public function withStatus(ActionStatus $status): self
    {
        return new self($this->id, $this->meetingId, $this->agendaItemId, $this->task, $this->assigneePersonId, $this->dueOn, $status);
    }

    public function isOverdue(AssociationDate $today): bool
    {
        return $this->status === ActionStatus::Open
            && $this->dueOn instanceof AssociationDate
            && $this->dueOn->isBefore($today);
    }
}
