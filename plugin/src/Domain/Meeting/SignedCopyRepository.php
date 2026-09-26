<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

interface SignedCopyRepository
{
    public function add(int $revisionId, string $mediaType, string $storageName): SignedCopy;

    /**
     * Mark every current copy of the revision as replaced by the given copy in one step,
     * so a revision cannot keep two current copies.
     *
     * @return int the number of copies that stopped being current.
     */
    public function replaceCurrent(int $revisionId, int $replacedBy): int;

    public function currentForRevision(int $revisionId): ?SignedCopy;

    public function find(int $id): ?SignedCopy;
}
