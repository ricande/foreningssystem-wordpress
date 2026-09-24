<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Document;

use InvalidArgumentException;

final class AssociationDocument
{
    public function __construct(
        private readonly ?int $id,
        string $title,
        private readonly DocumentVisibility $visibility,
        private readonly string $mediaType,
        private readonly string $storageName,
    ) {
        $this->title = trim($title);

        if ($this->title === '' || strlen($this->title) > 190) {
            throw new InvalidArgumentException('A document needs a title.');
        }

        if (! in_array($this->mediaType, [DocumentFileType::PDF, DocumentFileType::JPEG, DocumentFileType::PNG], true)) {
            throw new InvalidArgumentException('A document must be a PDF, JPEG, or PNG.');
        }

        if (preg_match('/^document-[a-f0-9]{64}\.(pdf|jpg|png)$/', $this->storageName) !== 1) {
            throw new InvalidArgumentException('The document file name is not valid.');
        }
    }

    private readonly string $title;

    public function id(): ?int
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

    public function storageName(): string
    {
        return $this->storageName;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->title, $this->visibility, $this->mediaType, $this->storageName);
    }

    public function withVisibility(DocumentVisibility $visibility): self
    {
        return new self($this->id, $this->title, $visibility, $this->mediaType, $this->storageName);
    }
}
