<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Infrastructure\Persistence\MigrationLock;
use Foreningssystem\Infrastructure\Persistence\MigrationException;

final class WordpressMigrationLock implements MigrationLock
{
    private const NAME = 'assoc_schema_migrate';

    public function acquire(): void
    {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::NAME, 10));

        if ((string) $result !== '1') {
            throw new MigrationException('Could not lock the schema migration.');
        }
    }

    public function release(): void
    {
        global $wpdb;

        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::NAME));
    }
}
