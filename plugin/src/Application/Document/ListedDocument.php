<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Document;

use Foreningssystem\Domain\Document\DocumentVisibility;

final class ListedDocument
{
    public function __construct(
        private readonly int $id,
        private readonly string $title,
        private readonly DocumentVisibility $visibility,
        private readonly string $mediaType,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function visibility(): DocumentVisibility
    {
        return $this->visibility;
    }

    public function mediaType(): string
    {
        return $this->mediaType;
    }
}
