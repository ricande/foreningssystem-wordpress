<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

interface SchemaVersionStore
{
    public function current(): int;

    public function store(int $version): void;
}
