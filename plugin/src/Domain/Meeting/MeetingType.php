<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

use InvalidArgumentException;

final class MeetingType
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $slug,
        private readonly string $name,
        private readonly int $sortOrder,
    ) {
        if ($this->id !== null && $this->id < 1) {
            throw new InvalidArgumentException('A saved meeting type needs a positive id.');
        }

        if (preg_match('/^[a-z0-9_]+$/', $this->slug) !== 1) {
            throw new InvalidArgumentException('A meeting type slug uses lowercase letters, digits, and underscores.');
        }

        if (trim($this->name) === '') {
            throw new InvalidArgumentException('A meeting type needs a name.');
        }

        if ($this->sortOrder < 0) {
            throw new InvalidArgumentException('Meeting type order cannot be negative.');
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->slug, $this->name, $this->sortOrder);
    }
}
