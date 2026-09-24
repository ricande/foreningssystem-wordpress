<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Guardian;

use Foreningssystem\Domain\Membership\AssociationDate;
use InvalidArgumentException;

final class GuardianRelationship
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $childPersonId,
        private readonly int $guardianPersonId,
        private readonly string $relationship,
        private readonly ?AssociationDate $startedOn,
        private readonly ?AssociationDate $endedOn,
    ) {
        if ($this->childPersonId < 1 || $this->guardianPersonId < 1 || $this->childPersonId === $this->guardianPersonId) {
            throw new InvalidArgumentException('A guardian relationship needs two different people.');
        }

        if (trim($this->relationship) === '') {
            throw new InvalidArgumentException('A guardian relationship needs a relationship.');
        }

        if (
            $this->startedOn instanceof AssociationDate
            && $this->endedOn instanceof AssociationDate
            && $this->endedOn->isBefore($this->startedOn)
        ) {
            throw new InvalidArgumentException('A guardian relationship cannot end before it starts.');
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function childPersonId(): int
    {
        return $this->childPersonId;
    }

    public function guardianPersonId(): int
    {
        return $this->guardianPersonId;
    }

    public function relationship(): string
    {
        return $this->relationship;
    }

    public function startedOn(): ?AssociationDate
    {
        return $this->startedOn;
    }

    public function endedOn(): ?AssociationDate
    {
        return $this->endedOn;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->childPersonId, $this->guardianPersonId, $this->relationship, $this->startedOn, $this->endedOn);
    }

    public function ended(AssociationDate $on): self
    {
        return new self($this->id, $this->childPersonId, $this->guardianPersonId, $this->relationship, $this->startedOn, $on);
    }
}
