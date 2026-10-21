<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Infrastructure\Files\PrivateStorageExposure;
use Foreningssystem\Infrastructure\Files\PrivateStorageLocation;

/**
 * Asks the site's own web server for test files in the private directory.
 *
 * One test file per stored file type (.pdf, .jpg, .png) is written with a random token and
 * nothing from the association, requested, and removed as soon as the answer is in. The
 * request goes to the site's own address. Only when that address cannot be reached at all,
 * as inside a container or on a host whose own name does not resolve locally, the same path
 * is asked from 127.0.0.1 with the site's host name. A local answer never replaces an answer
 * the site's own address gave. The result is kept for a day. See ADR-0037 and ADR-0039.
 */
final class PrivateStorageProbe
{
    public const TRANSIENT = 'assoc_private_storage_probe';

    /** The file endings the plugin stores. */
    private const ENDINGS = ['pdf', 'jpg', 'png'];

    public static function result(bool $fresh = false): string
    {
        return self::report($fresh)['result'];
    }

    /**
     * @return array{result: string, checked_at: int, address: string, outside: bool}
     */
    public static function report(bool $fresh = false): array
    {
        if (! PrivateUploadDirectory::isInsideWebRoot()) {
            return ['result' => PrivateStorageExposure::BLOCKED, 'checked_at' => 0, 'address' => '', 'outside' => true];
        }

        $directory = PrivateUploadDirectory::path();
        $cached = get_transient(self::TRANSIENT);

        if (
            ! $fresh
            && is_array($cached)
            && ($cached['directory'] ?? null) === $directory
            && is_string($cached['result'] ?? null)
        ) {
            return [
                'result' => $cached['result'],
                'checked_at' => (int) ($cached['checked_at'] ?? 0),
                'address' => is_string($cached['address'] ?? null) ? $cached['address'] : '',
                'outside' => false,
            ];
        }

        [$result, $address] = self::probe($directory);
        $checkedAt = time();
        set_transient(self::TRANSIENT, [
            'directory' => $directory,
            'result' => $result,
            'checked_at' => $checkedAt,
            'address' => $address,
        ], DAY_IN_SECONDS);

        return ['result' => $result, 'checked_at' => $checkedAt, 'address' => $address, 'outside' => false];
    }

    /**
     * @return array{0: string, 1: string} the result and the address that gave it.
     */
    private static function probe(string $directory): array
    {
        $base = self::url($directory);

        if ($base === null) {
            return [PrivateStorageExposure::UNKNOWN, ''];
        }

        /** @var array<string, string> $files name => token */
        $files = [];

        try {
            foreach (self::ENDINGS as $ending) {
                $token = bin2hex(random_bytes(16));
                // Not a name the plugin stores, so moving the storage root never picks it up.
                $name = 'probe-' . bin2hex(random_bytes(8)) . '.' . $ending;

                if (@file_put_contents($directory . '/' . $name, $token . "\n") === false) {
                    return [PrivateStorageExposure::UNKNOWN, ''];
                }

                $files[$name] = $token;
            }

            $site = self::ask($files, static function (string $name) use ($base): array {
                $url = apply_filters('foreningsplugin_private_storage_probe_url', $base . '/' . $name);

                return [is_string($url) ? $url : $base . '/' . $name, []];
            });

            if ($site !== null) {
                return [PrivateStorageExposure::combine($site['outcomes']), $site['address']];
            }

            $host = self::hostHeader($base);
            $path = (string) wp_parse_url($base, PHP_URL_PATH);

            if ($host === '') {
                return [PrivateStorageExposure::UNKNOWN, ''];
            }

            foreach (['http://127.0.0.1', 'https://127.0.0.1'] as $local) {
                $answer = self::ask($files, static fn (string $name): array => [$local . $path . '/' . $name, ['Host' => $host]]);

                if ($answer !== null) {
                    return [PrivateStorageExposure::combine($answer['outcomes']), $answer['address'] . ' (Host: ' . $host . ')'];
                }
            }

            return [PrivateStorageExposure::UNKNOWN, ''];
        } finally {
            foreach (array_keys($files) as $name) {
                @unlink($directory . '/' . $name);
            }
        }
    }

    /**
     * Requests every test file. Null when the address could not be reached for any of them,
     * so another address may be tried. An address that answered, whatever it answered, is
     * the answer.
     *
     * @param array<string, string> $files
     * @param callable(string): array{0: string, 1: array<string, string>} $target
     * @return array{outcomes: list<string>, address: string}|null
     */
    private static function ask(array $files, callable $target): ?array
    {
        $outcomes = [];
        $reached = false;
        $address = '';

        foreach ($files as $name => $token) {
            [$url, $headers] = $target($name);
            $address = self::origin($url);
            $response = wp_remote_get($url, [
                'timeout' => 5,
                'redirection' => 0,
                'headers' => $headers,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
            ]);

            if (is_wp_error($response)) {
                $outcomes[] = PrivateStorageExposure::UNKNOWN;

                continue;
            }

            $reached = true;
            $outcomes[] = PrivateStorageExposure::classify(
                (int) wp_remote_retrieve_response_code($response),
                (string) wp_remote_retrieve_body($response),
                $token
            );
        }

        return $reached ? ['outcomes' => $outcomes, 'address' => $address] : null;
    }

    private static function origin(string $url): string
    {
        $parts = wp_parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function hostHeader(string $url): string
    {
        $parts = wp_parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return '';
        }

        return $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
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
