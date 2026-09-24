<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

use InvalidArgumentException;

final class SignedCopy
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $revisionId,
        private readonly string $mediaType,
        private readonly string $storageName,
        private readonly ?int $replacedBy,
    ) {
        if ($this->revisionId < 1 || ($this->id !== null && $this->id < 1)) {
            throw new InvalidArgumentException('A signed copy belongs to a saved revision.');
        }

        SignedCopyType::extension($this->mediaType);

        if (! preg_match('/^signed-\d+-[a-f0-9]{64}\.(pdf|jpg|png)$/', $this->storageName)) {
            throw new InvalidArgumentException('The signed copy file name is not valid.');
        }

        if ($this->replacedBy !== null && $this->replacedBy < 1) {
            throw new InvalidArgumentException('A replaced signed copy points at a saved copy.');
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function revisionId(): int
    {
        return $this->revisionId;
    }

    public function mediaType(): string
    {
        return $this->mediaType;
    }

    public function storageName(): string
    {
        return $this->storageName;
    }

    public function replacedBy(): ?int
    {
        return $this->replacedBy;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->revisionId, $this->mediaType, $this->storageName, $this->replacedBy);
    }

    public function replacedByCopy(int $copyId): self
    {
        return new self($this->id, $this->revisionId, $this->mediaType, $this->storageName, $copyId);
    }
}
