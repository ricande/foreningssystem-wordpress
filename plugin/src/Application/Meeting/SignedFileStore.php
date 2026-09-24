<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

interface SignedFileStore
{
    public function put(string $name, string $bytes): void;

    public function read(string $name): string;

    public function discard(string $name): void;
}
