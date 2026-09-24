<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Infrastructure\Persistence\BaselineMigration;
use Foreningssystem\Infrastructure\Persistence\MigrationRunner;

final class WordpressMigrations
{
    public static function runner(): MigrationRunner
    {
        return new MigrationRunner(
            new WordpressSchemaVersionStore(),
            new WordpressMigrationLock(),
            [new BaselineMigration()]
        );
    }

    public static function migrateIfNeeded(): void
    {
        $runner = self::runner();

        if ($runner->currentVersion() >= $runner->latestVersion()) {
            return;
        }

        $runner->migrate();
    }
}
