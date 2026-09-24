<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Board;

use Foreningssystem\Domain\Membership\AssociationDate;
use InvalidArgumentException;

final class BoardAssignment
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $personId,
        private readonly int $roleId,
        private readonly AssociationDate $startedOn,
        private readonly ?AssociationDate $endedOn,
        private readonly string $publicContact,
        private readonly string $termLabel,
    ) {
        if ($this->personId < 1 || $this->roleId < 1) {
            throw new InvalidArgumentException('An assignment belongs to a saved person and role.');
        }

        if ($this->endedOn instanceof AssociationDate && $this->endedOn->isBefore($this->startedOn)) {
            throw new InvalidArgumentException('An assignment cannot end before it starts.');
        }

        if (strlen($this->publicContact) > 190 || strlen($this->termLabel) > 100) {
            throw new InvalidArgumentException('The public contact or term label is too long.');
        }

        if ($this->publicContact !== '' && str_contains($this->publicContact, '@') && filter_var($this->publicContact, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Public role contact is not a valid email.');
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

    public function roleId(): int
    {
        return $this->roleId;
    }

    public function startedOn(): AssociationDate
    {
        return $this->startedOn;
    }

    public function endedOn(): ?AssociationDate
    {
        return $this->endedOn;
    }

    public function publicContact(): string
    {
        return $this->publicContact;
    }

    public function termLabel(): string
    {
        return $this->termLabel;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->personId, $this->roleId, $this->startedOn, $this->endedOn, $this->publicContact, $this->termLabel);
    }

    public function withEnd(AssociationDate $on): self
    {
        return new self($this->id, $this->personId, $this->roleId, $this->startedOn, $on, $this->publicContact, $this->termLabel);
    }

    public function withoutPublicContact(): self
    {
        return new self($this->id, $this->personId, $this->roleId, $this->startedOn, $this->endedOn, '', $this->termLabel);
    }

    public function covers(AssociationDate $on): bool
    {
        if ($on->isBefore($this->startedOn)) {
            return false;
        }

        return ! $this->endedOn instanceof AssociationDate || ! $on->isAfter($this->endedOn);
    }

    public function overlaps(self $other): bool
    {
        if ($this->roleId !== $other->roleId) {
            return false;
        }

        if ($this->id !== null && $this->id === $other->id) {
            return false;
        }

        $startsBeforeOtherEnds = ! $other->endedOn instanceof AssociationDate || ! $this->startedOn->isAfter($other->endedOn);
        $otherStartsBeforeThisEnds = ! $this->endedOn instanceof AssociationDate || ! $other->startedOn->isAfter($this->endedOn);

        return $startsBeforeOtherEnds && $otherStartsBeforeThisEnds;
    }
}
