<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

use Foreningssystem\Domain\Membership\AssociationDate;
use InvalidArgumentException;

final class Decision
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $meetingId,
        private readonly ?int $agendaItemId,
        string $wording,
        private readonly ?int $responsiblePersonId,
        private readonly ?AssociationDate $deadline,
        private readonly DecisionFollowUp $followUp,
    ) {
        $this->wording = trim($wording);

        if ($this->meetingId < 1 || ($this->agendaItemId !== null && $this->agendaItemId < 1)) {
            throw new InvalidArgumentException('A decision belongs to a saved meeting.');
        }

        if ($this->responsiblePersonId !== null && $this->responsiblePersonId < 1) {
            throw new InvalidArgumentException('A responsible person must be saved.');
        }

        if ($this->wording === '' || strlen($this->wording) > 4000) {
            throw new InvalidArgumentException('A decision needs wording.');
        }
    }

    private readonly string $wording;

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

    public function wording(): string
    {
        return $this->wording;
    }

    public function responsiblePersonId(): ?int
    {
        return $this->responsiblePersonId;
    }

    public function deadline(): ?AssociationDate
    {
        return $this->deadline;
    }

    public function followUp(): DecisionFollowUp
    {
        return $this->followUp;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->meetingId, $this->agendaItemId, $this->wording, $this->responsiblePersonId, $this->deadline, $this->followUp);
    }

    public function revised(string $wording, ?int $responsiblePersonId, ?AssociationDate $deadline): self
    {
        return new self($this->id, $this->meetingId, $this->agendaItemId, $wording, $responsiblePersonId, $deadline, $this->followUp);
    }

    public function withFollowUp(DecisionFollowUp $followUp): self
    {
        return new self($this->id, $this->meetingId, $this->agendaItemId, $this->wording, $this->responsiblePersonId, $this->deadline, $followUp);
    }
}
