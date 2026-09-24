<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

use InvalidArgumentException;

final class MeetingTemplate
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $typeId,
        string $name,
    ) {
        $this->name = trim($name);

        if ($this->typeId < 1) {
            throw new InvalidArgumentException('A meeting template belongs to a saved meeting type.');
        }

        if ($this->name === '' || strlen($this->name) > 190) {
            throw new InvalidArgumentException('A meeting template needs a name.');
        }
    }

    private readonly string $name;

    public function id(): ?int
    {
        return $this->id;
    }

    public function typeId(): int
    {
        return $this->typeId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->typeId, $this->name);
    }
}
