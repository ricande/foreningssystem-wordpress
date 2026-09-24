<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

use InvalidArgumentException;

final class MembershipPeriod
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $membershipId,
        private readonly MembershipStatus $status,
        private readonly AssociationDate $startedOn,
        private readonly ?AssociationDate $endedOn,
        private readonly string $historicalClass = '',
    ) {
        if ($this->membershipId < 1) {
            throw new InvalidArgumentException('A membership period belongs to a saved membership.');
        }

        if ($this->endedOn instanceof AssociationDate && $this->endedOn->isBefore($this->startedOn)) {
            throw new InvalidArgumentException('A membership cannot end before it starts.');
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function membershipId(): int
    {
        return $this->membershipId;
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

    public function historicalClass(): string
    {
        return $this->historicalClass;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->membershipId, $this->status, $this->startedOn, $this->endedOn, $this->historicalClass);
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

    public function isActiveOn(AssociationDate $date): bool
    {
        if ($this->status !== MembershipStatus::Active && $this->status !== MembershipStatus::Ended) {
            return false;
        }

        if ($date->isBefore($this->startedOn)) {
            return false;
        }

        if (! $this->endedOn instanceof AssociationDate) {
            return $this->status === MembershipStatus::Active;
        }

        return ! $date->isAfter($this->endedOn);
    }

    public function coversAssignment(AssociationDate $startedOn, ?AssociationDate $endedOn): bool
    {
        if ($this->status !== MembershipStatus::Active && $this->status !== MembershipStatus::Ended) {
            return false;
        }

        if ($startedOn->isBefore($this->startedOn)) {
            return false;
        }

        if (! $this->endedOn instanceof AssociationDate) {
            return $this->status === MembershipStatus::Active;
        }

        if (! $endedOn instanceof AssociationDate) {
            return false;
        }

        return ! $endedOn->isAfter($this->endedOn);
    }
}
