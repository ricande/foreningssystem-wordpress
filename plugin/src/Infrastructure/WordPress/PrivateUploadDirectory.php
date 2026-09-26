<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Infrastructure\Files\PrivateStorageLocation;
use Foreningssystem\Infrastructure\Files\PrivateStorageRoots;

final class PrivateUploadDirectory
{
    public const ACK_OPTION = 'assoc_private_web_root_ack';

    /** The storage root this installation last wrote to. */
    public const ROOT_OPTION = 'assoc_private_storage_root';

    /** Roots the installation used before and that may still hold files. */
    public const EARLIER_ROOTS_OPTION = 'assoc_private_storage_earlier_roots';

    public static function path(): string
    {
        return self::roots()->active();
    }

    /**
     * The storage root that receives new files, together with every earlier root a file can
     * still be read from. Changing the configured directory moves the files it can move and
     * records the rest, so no stored file becomes unreachable.
     */
    public static function roots(): PrivateStorageRoots
    {
        $active = self::location()->directory();
        self::writeGuards($active);
        $recorded = get_option(self::ROOT_OPTION, '');
        $recorded = is_string($recorded) ? $recorded : '';
        $earlier = self::earlier();

        if ($recorded === $active && $earlier === []) {
            return new PrivateStorageRoots($active);
        }

        $roots = PrivateStorageRoots::settle($active, $recorded, $earlier, self::fallback());

        // The earlier roots are recorded first. A run that stops between the two writes
        // repeats the same move on the next request instead of forgetting a root.
        if ($roots->earlier() !== $earlier) {
            update_option(self::EARLIER_ROOTS_OPTION, $roots->earlier(), false);
        }

        if ($recorded !== $active) {
            update_option(self::ROOT_OPTION, $active, false);
        }

        return $roots;
    }

    public static function isInsideWebRoot(): bool
    {
        return self::location()->isInsideWebRoot();
    }

    public static function location(): PrivateStorageLocation
    {
        return PrivateStorageLocation::choose(
            self::webRoot(),
            self::configured(),
            self::fallback(),
            static fn (string $directory): bool => self::ensure($directory)
        );
    }

    /**
     * @return list<string>
     */
    private static function earlier(): array
    {
        $stored = get_option(self::EARLIER_ROOTS_OPTION, []);
        $roots = [];

        foreach (is_array($stored) ? $stored : [] as $root) {
            if (is_string($root) && trim($root) !== '') {
                $roots[] = PrivateStorageLocation::canonical($root);
            }
        }

        return array_values(array_unique($roots));
    }

    private static function webRoot(): string
    {
        return defined('ABSPATH') && is_string(ABSPATH) ? ABSPATH : '';
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
