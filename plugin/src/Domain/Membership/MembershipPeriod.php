<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

use InvalidArgumentException;

final class MembershipPeriod
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $personId,
        private readonly string $number,
        private readonly string $type,
        private readonly MembershipStatus $status,
        private readonly AssociationDate $startedOn,
        private readonly ?AssociationDate $endedOn,
    ) {
        if ($this->personId < 1) {
            throw new InvalidArgumentException('A membership belongs to a saved person.');
        }

        if (trim($this->number) === '' || trim($this->type) === '') {
            throw new InvalidArgumentException('A membership needs a number and a type.');
        }

        if ($this->endedOn instanceof AssociationDate && $this->endedOn->isBefore($this->startedOn)) {
            throw new InvalidArgumentException('A membership cannot end before it starts.');
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function personId(): int
    {
        return $this->personId;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function status(): MembershipStatus
    {
        return $this->status;
    }

    public function startedOn(): AssociationDate
    {
        return $this->startedOn;
    }

    public function endedOn(): ?AssociationDate
    {
        return $this->endedOn;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->personId, $this->number, $this->type, $this->status, $this->startedOn, $this->endedOn);
    }

    public function overlaps(self $other): bool
    {
        if ($this->id !== null && $this->id === $other->id) {
            return false;
        }

        $startsBeforeOtherEnds = ! $other->endedOn instanceof AssociationDate || ! $this->startedOn->isAfter($other->endedOn);
        $otherStartsBeforeThisEnds = ! $this->endedOn instanceof AssociationDate || ! $other->startedOn->isAfter($this->endedOn);

        return $startsBeforeOtherEnds && $otherStartsBeforeThisEnds;
    }

    public function countsAsActiveMember(): bool
    {
        return $this->status === MembershipStatus::Active && ! $this->endedOn instanceof AssociationDate;
    }
}
