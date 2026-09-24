<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

use InvalidArgumentException;

final class Participant
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $meetingId,
        private readonly int $personId,
        private readonly Presence $presence,
        private readonly MeetingDuty $duty,
    ) {
        if ($this->meetingId < 1 || $this->personId < 1) {
            throw new InvalidArgumentException('A participant belongs to a saved meeting and person.');
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function meetingId(): int
    {
        return $this->meetingId;
    }

    public function personId(): int
    {
        return $this->personId;
    }

    public function presence(): Presence
    {
        return $this->presence;
    }

    public function duty(): MeetingDuty
    {
        return $this->duty;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->meetingId, $this->personId, $this->presence, $this->duty);
    }
}
