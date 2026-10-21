<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\MemberRegisterBusy;
use Foreningssystem\Application\People\MemberRegisterLock;

/**
 * One MySQL advisory lock for the whole member register, held on the WordPress database
 * connection. The server releases it if the PHP process dies. See ADR-0031.
 */
final class WordpressMemberRegisterLock implements MemberRegisterLock
{
    private const SECONDS = 10;

    public function hold(callable $work): mixed
    {
        global $wpdb;

        $name = self::lockName();
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, self::SECONDS));

        if ((string) $result !== '1') {
            throw new MemberRegisterBusy('Another change to the member register holds it.');
        }

        try {
            return $work();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    /**
     * MySQL advisory locks live on the server, not in one database, so the name carries
     * the installation the register belongs to.
     */
    public static function lockName(): string
    {
        global $wpdb;

        $site = substr(hash('sha256', (string) $wpdb->dbname . '|' . (string) $wpdb->prefix), 0, 16);

        return 'assoc_register_' . $site;
    }
}
