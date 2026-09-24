<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

use InvalidArgumentException;

final class AgendaItem
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $meetingId,
        private readonly int $position,
        string $title,
        string $numberOverride,
    ) {
        $this->title = trim($title);
        $this->numberOverride = trim($numberOverride);

        if ($this->meetingId < 1 || $this->position < 1) {
            throw new InvalidArgumentException('An agenda item belongs to a saved meeting and a position.');
        }

        if ($this->title === '' || strlen($this->title) > 190) {
            throw new InvalidArgumentException('An agenda item needs a title.');
        }

        if (strlen($this->numberOverride) > 20) {
            throw new InvalidArgumentException('The agenda number is too long.');
        }
    }

    private readonly string $title;

    private readonly string $numberOverride;

    public function id(): ?int
    {
        return $this->id;
    }

    public function meetingId(): int
    {
        return $this->meetingId;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function numberOverride(): string
    {
        return $this->numberOverride;
    }

    public function displayNumber(): string
    {
        return $this->numberOverride !== '' ? $this->numberOverride : (string) $this->position;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->meetingId, $this->position, $this->title, $this->numberOverride);
    }

    public function withPosition(int $position): self
    {
        return new self($this->id, $this->meetingId, $position, $this->title, $this->numberOverride);
    }
}
