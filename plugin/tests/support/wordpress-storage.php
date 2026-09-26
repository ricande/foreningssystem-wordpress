<?php

/**
 * A small WordPress stand-in for private file storage.
 *
 * The unit suite has no WordPress and no uploads directory. These stubs let a test run the
 * real storage classes against a temporary directory and observe what they did on disk:
 * which file was written, which file was removed, and when. Options live in memory, so the
 * recorded storage roots behave the way they do on a site.
 */

declare(strict_types=1);

namespace Foreningssystem\Tests\Support {
    final class WordPressStorage
    {
        /** @var array<string, mixed> */
        public static array $options = [];

        public static string $uploads = '';

        public static ?string $configured = null;

        public static function useUploads(string $directory): void
        {
            self::reset();
            self::$uploads = $directory;
        }

        public static function reset(): void
        {
            self::$options = [];
            self::$uploads = '';
            self::$configured = null;
        }
    }
}

namespace Foreningssystem\Infrastructure\WordPress {

    use Foreningssystem\Tests\Support\WordPressStorage;

    /**
     * @return array<string, mixed>
     */
    function wp_upload_dir(): array
    {
        return ['basedir' => WordPressStorage::$uploads, 'error' => false];
    }

    function wp_mkdir_p(string $directory): bool
    {
        return is_dir($directory) || mkdir($directory, 0o755, true);
    }

    function get_option(string $name, mixed $default = false): mixed
    {
        return WordPressStorage::$options[$name] ?? $default;
    }

    function update_option(string $name, mixed $value, mixed $autoload = null): bool
    {
        unset($autoload);
        WordPressStorage::$options[$name] = $value;

        return true;
    }

    function delete_option(string $name): bool
    {
        unset(WordPressStorage::$options[$name]);

        return true;
    }

    function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed
    {
        unset($arguments);

        if ($hook === 'foreningsplugin_private_directory' && is_string(WordPressStorage::$configured)) {
            return WordPressStorage::$configured;
        }

        return $value;
    }
}

namespace {
    if (! defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
}
