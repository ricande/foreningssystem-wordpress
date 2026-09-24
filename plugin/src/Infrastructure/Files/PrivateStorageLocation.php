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
        $directory = self::normalize($directory);
        $webRoot = self::normalize($webRoot);

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

        if ($configured !== '' && $configured !== $fallback && $usable($configured)) {
            return new self($configured, self::placementOf($configured, $webRoot));
        }

        if (! $usable($fallback)) {
            throw new \RuntimeException('The private file directory could not be created.');
        }

        return new self($fallback, self::placementOf($fallback, $webRoot));
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
