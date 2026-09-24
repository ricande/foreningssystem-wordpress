<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

use InvalidArgumentException;

final class MembershipParticipant
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $membershipId,
        private readonly int $personId,
        private readonly ParticipantRole $role,
        private readonly bool $primary,
        private readonly AssociationDate $startedOn,
        private readonly ?AssociationDate $endedOn,
    ) {
        if ($this->membershipId < 1 || $this->personId < 1) {
            throw new InvalidArgumentException('A participant belongs to a saved membership and person.');
        }

        if ($this->endedOn instanceof AssociationDate && $this->endedOn->isBefore($this->startedOn)) {
            throw new InvalidArgumentException('Participation cannot end before it starts.');
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

    public function personId(): int
    {
        return $this->personId;
    }

    public function role(): ParticipantRole
    {
        return $this->role;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    public function startedOn(): AssociationDate
    {
        return $this->startedOn;
    }

    public function endedOn(): ?AssociationDate
    {
        return $this->endedOn;
    }

    public function countsAsMemberOn(AssociationDate $date): bool
    {
        if (! $this->role->countsAsMember() || $date->isBefore($this->startedOn)) {
            return false;
        }

        return ! ($this->endedOn instanceof AssociationDate && $date->isAfter($this->endedOn));
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

    public function withId(int $id): self
    {
        return new self($id, $this->membershipId, $this->personId, $this->role, $this->primary, $this->startedOn, $this->endedOn);
    }

    public function ended(AssociationDate $on): self
    {
        return new self($this->id, $this->membershipId, $this->personId, $this->role, $this->primary, $this->startedOn, $on);
    }
}
