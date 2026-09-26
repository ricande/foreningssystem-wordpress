<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Meeting\SignedCopyBusy;
use Foreningssystem\Application\Meeting\SignedCopyLock;

final class WordpressSignedCopyLock implements SignedCopyLock
{
    private const SECONDS = 10;

    public function acquire(int $revisionId): void
    {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::lockName($revisionId), self::SECONDS));

        if ((string) $result !== '1') {
            throw new SignedCopyBusy('Another signed copy upload holds this revision.');
        }
    }

    public function release(int $revisionId): void
    {
        global $wpdb;

        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lockName($revisionId)));
    }

    /**
     * MySQL advisory locks live on the server, not in one database, so the name carries
     * the installation the revision belongs to.
     */
    public static function lockName(int $revisionId): string
    {
        global $wpdb;

        $site = substr(hash('sha256', (string) $wpdb->dbname . '|' . (string) $wpdb->prefix), 0, 16);

        return 'assoc_signed_' . $site . '_' . $revisionId;
    }
}
