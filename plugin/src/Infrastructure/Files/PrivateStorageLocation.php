<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Files;

final class PrivateStorageLocation
{
    public const OUTSIDE_WEB_ROOT = 'outside_web_root';

    public const INSIDE_WEB_ROOT = 'inside_web_root';

    public function __construct(
        private readonly string $directory,
        private readonly string $placement,
    ) {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function placement(): string
    {
        return $this->placement;
    }

    public function isInsideWebRoot(): bool
    {
        return $this->placement === self::INSIDE_WEB_ROOT;
    }

    public static function placementOf(string $directory, string $webRoot): string
    {
        $directory = self::canonical($directory);
        $webRoot = self::canonical($webRoot);

        if ($webRoot !== '' && ($directory === $webRoot || str_starts_with($directory, $webRoot . '/'))) {
            return self::INSIDE_WEB_ROOT;
        }

        return self::OUTSIDE_WEB_ROOT;
    }

    /**
     * @param callable(string): bool $usable
     */
    public static function choose(string $webRoot, ?string $configured, string $fallback, callable $usable): self
    {
        $webRoot = self::normalize($webRoot);
        $fallback = self::normalize($fallback);
        $configured = self::normalize($configured ?? '');

        if (
            $configured !== ''
            && self::canonical($configured) !== self::canonical($fallback)
            && $usable($configured)
        ) {
            return new self(self::canonical($configured), self::placementOf($configured, $webRoot));
        }

        if (! $usable($fallback)) {
            throw new \RuntimeException('The private file directory could not be created.');
        }

        return new self(self::canonical($fallback), self::placementOf($fallback, $webRoot));
    }

    public static function isDocumentName(string $name): bool
    {
        return self::isFlatName($name) && preg_match('/^document-[a-f0-9]{64}\.(pdf|jpg|png)$/', $name) === 1;
    }

    public static function isSignedName(string $name): bool
    {
        return self::isFlatName($name) && preg_match('/^signed-\d+-[a-f0-9]{64}\.(pdf|jpg|png)$/', $name) === 1;
    }

    public static function isRevisionPdfName(string $name, int $revisionId): bool
    {
        return self::isFlatName($name) && preg_match('/^revision-' . $revisionId . '-[a-f0-9]{64}\.pdf$/', $name) === 1;
    }

    private static function isFlatName(string $name): bool
    {
        return $name !== ''
            && ! str_contains($name, '/')
            && ! str_contains($name, '\\')
            && ! str_contains($name, '..')
            && basename($name) === $name;
    }

    /**
     * The path the filesystem really means: the resolved path of the deepest part that
     * exists, with the part that does not exist yet appended.
     *
     * Comparing spelled-out paths is not enough to tell whether a directory sits under the
     * web root. A symlink, or a `..` segment in a path whose parent is a symlink, can spell
     * a directory that looks external while the bytes land inside the web root. A path that
     * does not exist at all keeps its normalized spelling, so the fallback and the warning
     * still work on a host where nothing has been created yet.
     */
    public static function canonical(string $path): string
    {
        $spelled = str_replace('\\', '/', trim($path));

        if ($spelled === '') {
            return '';
        }

        $missing = [];
        $candidate = $spelled;

        while (true) {
            // realpath resolves `..` against the directory a symlink points at, which is
            // what the filesystem does. Collapsing the path as text first would not.
            $real = realpath($candidate);

            if (is_string($real) && $real !== '') {
                $resolved = self::normalize($real);

                return $missing === [] ? $resolved : self::normalize($resolved . '/' . implode('/', array_reverse($missing)));
            }

            $parent = dirname($candidate);

            if ($parent === $candidate || $parent === '' || $parent === '.') {
                return self::normalize($spelled);
            }

            $missing[] = basename($candidate);
            $candidate = $parent;
        }
    }

    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $absolute = str_starts_with($path, '/');
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return ($absolute ? '/' : '') . implode('/', $parts);
    }
}
