<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Files\PrivateStorageExposed;
use Foreningssystem\Infrastructure\Files\PrivateStorageExposure;

/**
 * Refuses to write a private file into a directory the web server serves. See ADR-0038.
 */
final class PrivateStorageGuard
{
    /**
     * @param bool $fresh ask the web server now instead of using the answer kept for the
     *                    day. An upload asks now, because it is rare and its file is the
     *                    one that would be exposed.
     */
    public static function assertWritable(bool $fresh): void
    {
        if (PrivateStorageProbe::result($fresh) === PrivateStorageExposure::EXPOSED) {
            throw new PrivateStorageExposed('The web server serves the private directory.');
        }
    }
}
