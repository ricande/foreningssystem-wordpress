<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

use InvalidArgumentException;

final class Meeting
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $typeId,
        string $title,
        private readonly MeetingMoment $startsAt,
        string $place,
        private readonly MeetingStatus $status,
    ) {
        $this->title = trim($title);
        $this->place = trim($place);

        if ($this->typeId < 1) {
            throw new InvalidArgumentException('A meeting belongs to a saved type.');
        }

        if ($this->title === '' || strlen($this->title) > 190) {
            throw new InvalidArgumentException('A meeting needs a title.');
        }

        if (strlen($this->place) > 190) {
            throw new InvalidArgumentException('The place name is too long.');
        }
    }

    private readonly string $title;

    private readonly string $place;

    public function id(): ?int
    {
        return $this->id;
    }

    public function typeId(): int
    {
        return $this->typeId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function startsAt(): MeetingMoment
    {
        return $this->startsAt;
    }

    public function place(): string
    {
        return $this->place;
    }

    public function status(): MeetingStatus
    {
        return $this->status;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->typeId, $this->title, $this->startsAt, $this->place, $this->status);
    }

    public function withStatus(MeetingStatus $status): self
    {
        return new self($this->id, $this->typeId, $this->title, $this->startsAt, $this->place, $status);
    }

    public function withHeader(int $typeId, string $title, MeetingMoment $startsAt, string $place): self
    {
        return new self($this->id, $typeId, $title, $startsAt, $place, $this->status);
    }
}
