<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

final class PersonalizedOutput
{
    /**
     * Output that depends on the logged-in visitor must not be stored as one shared page
     * cache entry, and must not be kept by a browser or a proxy either.
     */
    public static function doNotCache(): void
    {
        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
    }
}
