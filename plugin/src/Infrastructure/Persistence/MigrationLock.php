<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

interface MigrationLock
{
    public function acquire(): void;

    public function release(): void;
}
