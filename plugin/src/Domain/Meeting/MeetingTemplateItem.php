<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

use InvalidArgumentException;

final class MeetingTemplateItem
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $templateId,
        private readonly int $position,
        string $title,
    ) {
        $this->title = trim($title);

        if ($this->templateId < 1 || $this->position < 1) {
            throw new InvalidArgumentException('A template heading belongs to a saved template and a position.');
        }

        if ($this->title === '' || strlen($this->title) > 190) {
            throw new InvalidArgumentException('A template heading needs a title.');
        }
    }

    private readonly string $title;

    public function id(): ?int
    {
        return $this->id;
    }

    public function templateId(): int
    {
        return $this->templateId;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->templateId, $this->position, $this->title);
    }
}
