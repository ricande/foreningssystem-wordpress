<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Document;

interface DocumentFileStore
{
    public function put(string $name, string $bytes): void;

    public function read(string $name): string;
}
