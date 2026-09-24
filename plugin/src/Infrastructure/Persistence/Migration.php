<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

interface Migration
{
    public function version(): int;

    public function up(): void;
}
