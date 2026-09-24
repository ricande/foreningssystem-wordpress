<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Infrastructure\Files\PrivateStorageLocation;

final class PrivateUploadDirectory
{
    public const ACK_OPTION = 'assoc_private_web_root_ack';

    public static function path(): string
    {
        $location = self::location();
        self::moveExisting(self::fallback(), $location->directory());
        self::writeGuards($location->directory());

        return $location->directory();
    }

    public static function isInsideWebRoot(): bool
    {
        return self::location()->isInsideWebRoot();
    }

    public static function location(): PrivateStorageLocation
    {
        return PrivateStorageLocation::choose(
            ABSPATH,
            self::configured(),
            self::fallback(),
            static fn (string $directory): bool => self::ensure($directory)
        );
    }

    private static function configured(): ?string
    {
        $configured = '';

        if (defined('FORENINGSPLUGIN_PRIVATE_DIR') && is_string(FORENINGSPLUGIN_PRIVATE_DIR)) {
            $configured = FORENINGSPLUGIN_PRIVATE_DIR;
        }

        $filtered = apply_filters('foreningsplugin_private_directory', $configured);

        return is_string($filtered) && trim($filtered) !== '' ? $filtered : null;
    }

    private static function fallback(): string
    {
        $uploads = wp_upload_dir();

        if (! empty($uploads['error']) || ! is_string($uploads['basedir']) || $uploads['basedir'] === '') {
            throw new \RuntimeException('The upload directory is not available.');
        }

        return $uploads['basedir'] . '/assoc-private';
    }

    private static function ensure(string $directory): bool
    {
        return $directory !== '' && (is_dir($directory) || wp_mkdir_p($directory));
    }

    private static function moveExisting(string $from, string $to): void
    {
        $from = PrivateStorageLocation::normalize($from);
        $to = PrivateStorageLocation::normalize($to);

        if ($from === $to || ! is_dir($from)) {
            return;
        }

        $names = scandir($from);

        if (! is_array($names)) {
            return;
        }

        foreach ($names as $name) {
            if (
                ! is_string($name)
                || (
                    ! PrivateStorageLocation::isDocumentName($name)
                    && ! PrivateStorageLocation::isSignedName($name)
                    && ! preg_match('/^revision-\d+-[a-f0-9]{64}\.pdf$/', $name)
                )
            ) {
                continue;
            }

            $source = $from . '/' . $name;
            $target = $to . '/' . $name;

            if (! is_file($source) || is_file($target)) {
                continue;
            }

            if (! rename($source, $target) && copy($source, $target)) {
                unlink($source);
            }
        }
    }

    private static function writeGuards(string $directory): void
    {
        $deny = $directory . '/.htaccess';

        if (! is_file($deny)) {
            file_put_contents($deny, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }

        $index = $directory . '/index.php';

        if (! is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }
}
