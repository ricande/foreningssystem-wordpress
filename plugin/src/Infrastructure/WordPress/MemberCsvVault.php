<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\MemberCsvException;

/**
 * Holds one uploaded spreadsheet per officer, outside the public tree, under a random name.
 * The browser filename is never used as a path. The file is removed on confirm, cancel, or expiry.
 */
final class MemberCsvVault
{
    /**
     * @return array{token: string, path: string, delimiter: string, mapping: array<int, string>, hash: string}|null
     */
    public static function current(): ?array
    {
        $session = get_transient(self::sessionKey());

        if (! is_array($session) || ! isset($session['token'], $session['path'], $session['delimiter'], $session['hash'])) {
            return null;
        }

        if (! is_string($session['token']) || ! is_string($session['path']) || ! is_string($session['delimiter']) || ! is_string($session['hash'])) {
            return null;
        }

        if (preg_match('/\A[a-f0-9]{32}\z/', $session['token']) !== 1) {
            return null;
        }

        $root = realpath(self::directory());
        $path = realpath($session['path']);

        if ($root === false || $path === false || ! str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (basename($path) !== $session['token'] . '.csv') {
            return null;
        }

        $mapping = [];

        if (isset($session['mapping']) && is_array($session['mapping'])) {
            foreach ($session['mapping'] as $index => $field) {
                if (is_string($field)) {
                    $mapping[(int) $index] = $field;
                }
            }
        }

        return [
            'token' => $session['token'],
            'path' => $path,
            'delimiter' => $session['delimiter'] === ';' ? ';' : ',',
            'mapping' => $mapping,
            'hash' => $session['hash'],
        ];
    }

    public static function store(string $bytes, string $delimiter): void
    {
        self::discardFile();
        $token = bin2hex(random_bytes(16));
        $path = self::directory() . '/' . $token . '.csv';

        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            self::unlinkOwned($path, $token);

            throw new MemberCsvException('unreadable');
        }

        chmod($path, 0600);
        self::remember([
            'token' => $token,
            'path' => $path,
            'delimiter' => $delimiter === ';' ? ';' : ',',
            'mapping' => [],
            'hash' => hash('sha256', $bytes),
        ]);
    }

    /**
     * @param array<int, string> $mapping
     */
    public static function saveMapping(string $delimiter, array $mapping): bool
    {
        $session = self::current();

        if ($session === null) {
            return false;
        }

        $session['delimiter'] = $delimiter === ';' ? ';' : ',';
        $session['mapping'] = $mapping;
        self::remember($session);

        return true;
    }

    public static function contents(): ?string
    {
        $session = self::current();

        if ($session === null) {
            return null;
        }

        $bytes = file_get_contents($session['path']);

        return is_string($bytes) ? $bytes : null;
    }

    public static function discard(): void
    {
        self::discardFile();
        delete_transient(self::sessionKey());
        delete_transient(self::reportKey());
    }

    /**
     * Drops the current upload after a failed confirm. The previous result report stays.
     */
    public static function release(): void
    {
        self::discardFile();
        delete_transient(self::sessionKey());
    }

    /**
     * Removes this officer's expired spreadsheet files. A name that is not the random
     * 32-hex upload, a directory, or a symlink is left in place.
     */
    public static function purgeExpiredUploads(): void
    {
        $directory = self::directoryPath();

        if (! is_dir($directory)) {
            return;
        }

        self::purgeExpired($directory);
    }

    /**
     * @param list<array{line: int, kind: string, code: string}> $issues
     */
    public static function finish(string $hash, int $imported, int $duplicates, int $failed, array $issues): void
    {
        self::discardFile();
        delete_transient(self::sessionKey());
        set_transient(self::reportKey(), [
            'hash' => $hash,
            'imported' => $imported,
            'duplicates' => $duplicates,
            'failed' => $failed,
            'issues' => $issues,
        ], HOUR_IN_SECONDS);
    }

    /**
     * @return array{hash: string, imported: int, duplicates: int, failed: int, issues: list<array{line: int, kind: string, code: string}>}|null
     */
    public static function report(): ?array
    {
        $report = get_transient(self::reportKey());

        if (! is_array($report) || ! isset($report['hash'], $report['imported'], $report['duplicates'], $report['failed']) || ! is_string($report['hash'])) {
            return null;
        }

        $issues = [];

        if (isset($report['issues']) && is_array($report['issues'])) {
            foreach ($report['issues'] as $issue) {
                if (! is_array($issue) || ! isset($issue['line'], $issue['kind'], $issue['code'])) {
                    continue;
                }

                if (! is_string($issue['kind']) || ! is_string($issue['code'])) {
                    continue;
                }

                $issues[] = [
                    'line' => (int) $issue['line'],
                    'kind' => $issue['kind'],
                    'code' => $issue['code'],
                ];
            }
        }

        return [
            'hash' => $report['hash'],
            'imported' => (int) $report['imported'],
            'duplicates' => (int) $report['duplicates'],
            'failed' => (int) $report['failed'],
            'issues' => $issues,
        ];
    }

    private static function sessionKey(): string
    {
        return 'assoc_member_csv_session_' . get_current_user_id();
    }

    private static function reportKey(): string
    {
        return 'assoc_member_csv_report_' . get_current_user_id();
    }

    /**
     * @param array{token: string, path: string, delimiter: string, mapping: array<int, string>, hash: string} $session
     */
    private static function remember(array $session): void
    {
        set_transient(self::sessionKey(), $session, HOUR_IN_SECONDS);
    }

    private static function discardFile(): void
    {
        $session = get_transient(self::sessionKey());

        if (! is_array($session) || ! isset($session['path'], $session['token']) || ! is_string($session['path']) || ! is_string($session['token'])) {
            return;
        }

        self::unlinkOwned($session['path'], $session['token']);
    }

    private static function unlinkOwned(string $path, string $token): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $token) !== 1 || basename($path) !== $token . '.csv' || is_link($path)) {
            return;
        }

        $root = realpath(self::directoryPath());
        $parent = realpath(dirname($path));

        if ($root === false || $parent === false || $parent !== $root || ! is_file($path)) {
            return;
        }

        unlink($path);
    }

    private static function directory(): string
    {
        $directory = self::directoryPath();

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new MemberCsvException('unreadable');
        }

        self::purgeExpired($directory);

        return $directory;
    }

    private static function directoryPath(): string
    {
        return sys_get_temp_dir() . '/assoc-member-csv-' . get_current_user_id();
    }

    private static function purgeExpired(string $directory): void
    {
        $root = realpath($directory);

        if ($root === false) {
            return;
        }

        $entries = scandir($root);

        if ($entries === false) {
            return;
        }

        $active = self::activeToken();
        $cutoff = time() - HOUR_IN_SECONDS;

        foreach ($entries as $name) {
            if (preg_match('/\A([a-f0-9]{32})\.csv\z/', $name, $match) !== 1) {
                continue;
            }

            if ($active !== null && hash_equals($active, $match[1])) {
                continue;
            }

            $path = $root . DIRECTORY_SEPARATOR . $name;

            if (is_link($path) || ! is_file($path)) {
                continue;
            }

            $modified = filemtime($path);

            if ($modified !== false && $modified <= $cutoff) {
                unlink($path);
            }
        }
    }

    private static function activeToken(): ?string
    {
        $session = get_transient(self::sessionKey());

        if (! is_array($session) || ! isset($session['token']) || ! is_string($session['token'])) {
            return null;
        }

        return preg_match('/\A[a-f0-9]{32}\z/', $session['token']) === 1 ? $session['token'] : null;
    }
}
