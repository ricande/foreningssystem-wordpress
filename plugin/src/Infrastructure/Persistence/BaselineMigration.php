<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

final class BaselineMigration implements Migration
{
    public function version(): int
    {
        return 1;
    }

    public function up(): void
    {
    }
}
