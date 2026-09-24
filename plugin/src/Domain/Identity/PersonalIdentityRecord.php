<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Identity;

use Foreningssystem\Domain\Membership\AssociationDate;
use InvalidArgumentException;

final class PersonalIdentityRecord
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $personId,
        private readonly PersonalIdentityNumber $number,
        private readonly string $purpose,
        private readonly string $basisNote,
        private readonly AssociationDate $collectedOn,
        private readonly ?int $recordedByUserId,
    ) {
        if ($this->personId < 1 || trim($this->purpose) === '') {
            throw new InvalidArgumentException('A personal identity record needs a person and a purpose.');
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

    public function number(): PersonalIdentityNumber
    {
        return $this->number;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function basisNote(): string
    {
        return $this->basisNote;
    }

    public function collectedOn(): AssociationDate
    {
        return $this->collectedOn;
    }

    public function recordedByUserId(): ?int
    {
        return $this->recordedByUserId;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->personId, $this->number, $this->purpose, $this->basisNote, $this->collectedOn, $this->recordedByUserId);
    }
}
