<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

final class Cli
{
    public static function register(): void
    {
        \WP_CLI::add_command('assoc migrate', static function (): void {
            try {
                $runner = WordpressMigrations::runner();
                $runner->migrate();
                \WP_CLI::success('Schema version ' . (string) $runner->currentVersion());
            } catch (\Throwable $error) {
                \WP_CLI::error($error->getMessage());
            }
        });
    }
}
