<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

/**
 * Serializes the replacement of the current signed copy of one minutes revision.
 *
 * Reading the current copy, storing the new one and marking the old one replaced is a
 * read-modify-write sequence. Two officers who upload at the same time must not both
 * become "current", so the sequence runs under an exclusive claim on the revision.
 */
interface SignedCopyLock
{
    /**
     * @throws SignedCopyBusy when another upload holds the revision.
     */
    public function acquire(int $revisionId): void;

    public function release(int $revisionId): void;
}
