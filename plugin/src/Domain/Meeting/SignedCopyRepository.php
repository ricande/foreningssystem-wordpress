<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

interface SignedCopyRepository
{
    public function add(int $revisionId, string $mediaType, string $storageName): SignedCopy;

    public function markReplaced(int $id, int $replacedBy): void;

    public function currentForRevision(int $revisionId): ?SignedCopy;

    public function find(int $id): ?SignedCopy;
}
