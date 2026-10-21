<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Infrastructure\Files\PrivateStorageExposure;
use Foreningssystem\Infrastructure\Files\PrivateStorageLocation;

/**
 * Asks the site's own web server for a test file in the private directory.
 *
 * The request goes to this site's own address and nowhere else. The test file holds a
 * random token and nothing from the association, and it is removed as soon as the answer is
 * in. The answer is kept for a day, so an admin page does not wait for it every time.
 */
final class PrivateStorageProbe
{
    public const TRANSIENT = 'assoc_private_storage_probe';

    public static function result(bool $fresh = false): string
    {
        if (! PrivateUploadDirectory::isInsideWebRoot()) {
            return PrivateStorageExposure::BLOCKED;
        }

        $directory = PrivateUploadDirectory::path();
        $cached = get_transient(self::TRANSIENT);

        if (
            ! $fresh
            && is_array($cached)
            && ($cached['directory'] ?? null) === $directory
            && is_string($cached['result'] ?? null)
        ) {
            return $cached['result'];
        }

        $result = self::probe($directory);
        set_transient(self::TRANSIENT, ['directory' => $directory, 'result' => $result], DAY_IN_SECONDS);

        return $result;
    }

    private static function probe(string $directory): string
    {
        $base = self::url($directory);

        if ($base === null) {
            return PrivateStorageExposure::UNKNOWN;
        }

        $token = bin2hex(random_bytes(16));
        // Not a name the plugin stores, so moving the storage root never picks it up.
        $name = 'probe-' . bin2hex(random_bytes(8)) . '.pdf';
        $path = $directory . '/' . $name;

        if (@file_put_contents($path, "%PDF-1.4\n%" . $token . "\n") === false) {
            return PrivateStorageExposure::UNKNOWN;
        }

        try {
            $url = apply_filters('foreningsplugin_private_storage_probe_url', $base . '/' . $name);
            $response = wp_remote_get(is_string($url) ? $url : $base . '/' . $name, [
                'timeout' => 5,
                'redirection' => 0,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
            ]);

            if (is_wp_error($response)) {
                return PrivateStorageExposure::UNKNOWN;
            }

            return PrivateStorageExposure::classify(
                (int) wp_remote_retrieve_response_code($response),
                (string) wp_remote_retrieve_body($response),
                $token
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * The address the web server would serve the directory at, or null when it cannot be
     * told from the WordPress paths.
     */
    private static function url(string $directory): ?string
    {
        $uploads = wp_upload_dir(null, false);
        $baseDirectory = is_string($uploads['basedir'] ?? null) ? PrivateStorageLocation::canonical($uploads['basedir']) : '';
        $baseUrl = is_string($uploads['baseurl'] ?? null) ? $uploads['baseurl'] : '';

        if ($baseDirectory !== '' && $baseUrl !== '' && str_starts_with($directory . '/', $baseDirectory . '/')) {
            return untrailingslashit($baseUrl) . substr($directory, strlen($baseDirectory));
        }

        $root = PrivateStorageLocation::canonical(ABSPATH);

        if ($root !== '' && str_starts_with($directory . '/', $root . '/')) {
            return untrailingslashit(site_url()) . substr($directory, strlen($root));
        }

        return null;
    }
}
