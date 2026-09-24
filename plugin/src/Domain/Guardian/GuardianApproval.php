<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Guardian;

use DateTimeImmutable;
use InvalidArgumentException;

final class GuardianApproval
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $childPersonId,
        private readonly int $guardianPersonId,
        private readonly string $purpose,
        private readonly string $basisNote,
        private readonly DateTimeImmutable $approvedAt,
        private readonly string $method,
        private readonly string $noticeVersion,
        private readonly ?int $recordedByUserId,
        private readonly ?DateTimeImmutable $withdrawnAt,
        private readonly string $note,
    ) {
        if ($this->childPersonId < 1 || $this->guardianPersonId < 1 || trim($this->purpose) === '' || trim($this->method) === '') {
            throw new InvalidArgumentException('A guardian approval needs the people, a purpose, and a method.');
        }

        if ($this->withdrawnAt instanceof DateTimeImmutable && $this->withdrawnAt < $this->approvedAt) {
            throw new InvalidArgumentException('A guardian approval cannot be withdrawn before it was recorded.');
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

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function basisNote(): string
    {
        return $this->basisNote;
    }

    public function approvedAt(): DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function noticeVersion(): string
    {
        return $this->noticeVersion;
    }

    public function recordedByUserId(): ?int
    {
        return $this->recordedByUserId;
    }

    public function withdrawnAt(): ?DateTimeImmutable
    {
        return $this->withdrawnAt;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->childPersonId,
            $this->guardianPersonId,
            $this->purpose,
            $this->basisNote,
            $this->approvedAt,
            $this->method,
            $this->noticeVersion,
            $this->recordedByUserId,
            $this->withdrawnAt,
            $this->note
        );
    }

    public function withdrawn(DateTimeImmutable $at): self
    {
        return new self(
            $this->id,
            $this->childPersonId,
            $this->guardianPersonId,
            $this->purpose,
            $this->basisNote,
            $this->approvedAt,
            $this->method,
            $this->noticeVersion,
            $this->recordedByUserId,
            $at,
            $this->note
        );
    }
}
