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
                WordpressAccess::sync();
                \WP_CLI::success('Schema version ' . (string) $runner->currentVersion());
            } catch (\Throwable $error) {
                \WP_CLI::error($error->getMessage());
            }
        });

        \WP_CLI::add_command('assoc sync-roles', static function (): void {
            WordpressAccess::sync();
            \WP_CLI::success('Association roles synced.');
        });
    }
}
