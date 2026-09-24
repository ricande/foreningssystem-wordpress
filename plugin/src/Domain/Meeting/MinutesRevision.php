<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

use InvalidArgumentException;

final class MinutesRevision
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $minutesId,
        private readonly int $meetingId,
        private readonly int $number,
        private readonly RevisionState $state,
        string $body,
        string $payload,
        private readonly bool $handEdited,
        private readonly ?int $correctsRevisionId = null,
        private readonly ?int $supersededBy = null,
    ) {
        $this->body = trim($body);
        $this->payload = trim($payload);

        if ($this->minutesId < 1 || $this->meetingId < 1 || $this->number < 1) {
            throw new InvalidArgumentException('A minutes revision belongs to a saved meeting.');
        }

        if ($this->body === '') {
            throw new InvalidArgumentException('A minutes revision needs text.');
        }

        if ($this->payload === '') {
            throw new InvalidArgumentException('A minutes revision needs its source payload.');
        }

        if ($this->correctsRevisionId !== null && $this->correctsRevisionId < 1) {
            throw new InvalidArgumentException('A correction points at a saved revision.');
        }

        if ($this->supersededBy !== null && ($this->supersededBy < 1 || $this->state !== RevisionState::Finalized)) {
            throw new InvalidArgumentException('Only a finalized revision can be superseded.');
        }
    }

    private readonly string $body;

    private readonly string $payload;

    public function id(): ?int
    {
        return $this->id;
    }

    public function minutesId(): int
    {
        return $this->minutesId;
    }

    public function meetingId(): int
    {
        return $this->meetingId;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function state(): RevisionState
    {
        return $this->state;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function payload(): string
    {
        return $this->payload;
    }

    public function handEdited(): bool
    {
        return $this->handEdited;
    }

    public function correctsRevisionId(): ?int
    {
        return $this->correctsRevisionId;
    }

    public function supersededBy(): ?int
    {
        return $this->supersededBy;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->minutesId, $this->meetingId, $this->number, $this->state, $this->body, $this->payload, $this->handEdited, $this->correctsRevisionId, $this->supersededBy);
    }

    public function withBody(string $body): self
    {
        return new self($this->id, $this->minutesId, $this->meetingId, $this->number, $this->state, $body, $this->payload, true, $this->correctsRevisionId, $this->supersededBy);
    }

    public function regenerated(string $body, string $payload): self
    {
        return new self($this->id, $this->minutesId, $this->meetingId, $this->number, $this->state, $body, $payload, false, $this->correctsRevisionId, $this->supersededBy);
    }

    public function withState(RevisionState $state): self
    {
        return new self($this->id, $this->minutesId, $this->meetingId, $this->number, $state, $this->body, $this->payload, $this->handEdited, $this->correctsRevisionId, $this->supersededBy);
    }

    public function markedSuperseded(int $revisionId): self
    {
        return new self($this->id, $this->minutesId, $this->meetingId, $this->number, $this->state, $this->body, $this->payload, $this->handEdited, $this->correctsRevisionId, $revisionId);
    }
}
