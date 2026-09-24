<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

interface MinutesPdfStore
{
    /**
     * @return array{hash: string, name: string}|null
     */
    public function stored(int $revisionId): ?array;

    public function put(int $revisionId, string $hash, string $bytes): void;

    public function read(int $revisionId): string;
}
