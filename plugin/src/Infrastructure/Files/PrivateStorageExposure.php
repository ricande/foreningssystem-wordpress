<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Files;

/**
 * What a request for a test file in the private directory showed about the web server.
 *
 * The answer decides the warning: an acknowledgement cannot hide a directory the web server
 * was seen serving, and a directory the web server was seen refusing needs no
 * acknowledgement. When the request did not give a clear answer, the administrator's
 * acknowledgement is all there is to go on, as before. See ADR-0037.
 */
final class PrivateStorageExposure
{
    /** The web server returned the test file. */
    public const EXPOSED = 'exposed';

    /** The web server refused the test file. */
    public const BLOCKED = 'blocked';

    /** The request failed or the answer was not clear. */
    public const UNKNOWN = 'unknown';

    /**
     * @param int|null $status HTTP status, or null when the request itself failed.
     */
    public static function classify(?int $status, string $body, string $token): string
    {
        if ($status === null || $token === '') {
            return self::UNKNOWN;
        }

        if ($status >= 200 && $status < 300 && str_contains($body, $token)) {
            return self::EXPOSED;
        }

        // 401, 403, 404 and 410 are a web server refusing the file. A redirect, a server
        // error, or a page without the token can be a maintenance page or a login wall in
        // front of the whole site, which says nothing about this directory.
        if (in_array($status, [401, 403, 404, 410], true)) {
            return self::BLOCKED;
        }

        return self::UNKNOWN;
    }

    public static function needsAttention(bool $insideWebRoot, string $probe, bool $acknowledged): bool
    {
        if (! $insideWebRoot) {
            return false;
        }

        return match ($probe) {
            self::EXPOSED => true,
            self::BLOCKED => false,
            default => ! $acknowledged,
        };
    }
}
