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
        private readonly ?AssociationDate $endedOn,
    ) {
        if ($this->membershipId < 1 || $this->personId < 1) {
            throw new InvalidArgumentException('A participant belongs to a saved membership and person.');
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

    public function endedOn(): ?AssociationDate
    {
        return $this->endedOn;
    }

    public function countsAsMemberOn(AssociationDate $date): bool
    {
        return $this->role->countsAsMember() && ! ($this->endedOn instanceof AssociationDate && $date->isAfter($this->endedOn));
    }

    public function withId(int $id): self
    {
        return new self($id, $this->membershipId, $this->personId, $this->role, $this->primary, $this->endedOn);
    }

    public function ended(AssociationDate $on): self
    {
        return new self($this->id, $this->membershipId, $this->personId, $this->role, $this->primary, $on);
    }
}
