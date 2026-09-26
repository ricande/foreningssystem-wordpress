<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Files;

/**
 * Where the private files of this installation are.
 *
 * One directory is active: new files are written there. A storage root is not part of the
 * database, so changing it must not make the files the database already points at
 * invisible. Every earlier root is therefore either emptied into the active one or kept in
 * the state, and a file is read from the active root first and from the earlier roots after
 * that. A file is never abandoned without being recorded.
 */
final class PrivateStorageRoots
{
    /**
     * @param list<string> $earlier roots that may still hold files.
     */
    public function __construct(
        private readonly string $active,
        private readonly array $earlier = [],
    ) {
    }

    public function active(): string
    {
        return $this->active;
    }

    /**
     * @return list<string>
     */
    public function earlier(): array
    {
        return $this->earlier;
    }

    /**
     * Move the private files of every earlier root into the active root and report the roots
     * that still hold files.
     *
     * A recorded root whose directory is unreachable right now stays in the state: an
     * unmounted volume is an unknown state, not an empty one.
     *
     * @param list<string> $earlier
     */
    public static function settle(string $active, string $recorded, array $earlier, string $fallback): self
    {
        $active = PrivateStorageLocation::canonical($active);
        /** @var array<string, bool> $sources true when the installation recorded the root. */
        $sources = [];

        foreach ([$recorded, ...$earlier] as $path) {
            $source = PrivateStorageLocation::canonical($path);

            if ($source === '' || $source === $active) {
                continue;
            }

            $sources[$source] = true;
        }

        $conventional = PrivateStorageLocation::canonical($fallback);

        if ($conventional !== '' && $conventional !== $active && ! isset($sources[$conventional])) {
            $sources[$conventional] = false;
        }

        $remaining = [];

        foreach ($sources as $source => $wasRecorded) {
            $source = (string) $source;
            self::moveInto($source, $active);

            if (self::holdsPrivateFiles($source) || ($wasRecorded && ! is_dir($source))) {
                $remaining[] = $source;
            }
        }

        return new self($active, $remaining);
    }

    /**
     * The path of an existing private file, in the active root or in an earlier one.
     */
    public function locate(string $name): ?string
    {
        if (! PrivateStorageLocation::isPrivateName($name)) {
            return null;
        }

        foreach ($this->roots() as $root) {
            $path = $root . '/' . $name;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Write a file into the active root.
     *
     * The bytes go to a working name first and are moved into place only when all of them
     * were stored, so the file never exists half written under the name a reader uses, and a
     * failed write leaves whatever was there before.
     */
    public function write(string $name, string $bytes): bool
    {
        if (! PrivateStorageLocation::isPrivateName($name)) {
            return false;
        }

        $path = $this->active . '/' . $name;
        $working = self::workingName($path);
        // A directory that cannot be written to is an answer, not a warning to print.
        $written = @file_put_contents($working, $bytes);

        if ($written === strlen($bytes) && @rename($working, $path)) {
            return true;
        }

        @unlink($working);

        return false;
    }

    /**
     * Remove the file from every root, so an old root cannot keep a copy of a file the
     * association discarded.
     */
    public function delete(string $name): void
    {
        if (! PrivateStorageLocation::isPrivateName($name)) {
            return;
        }

        foreach ($this->roots() as $root) {
            $path = $root . '/' . $name;

            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function roots(): array
    {
        return [$this->active, ...$this->earlier];
    }

    private static function moveInto(string $from, string $to): void
    {
        if ($from === $to || ! is_dir($from) || ! is_dir($to)) {
            return;
        }

        foreach (self::privateNames($from) as $name) {
            $source = $from . '/' . $name;
            $target = $to . '/' . $name;

            if (! is_file($source)) {
                continue;
            }

            if (is_file($target)) {
                // The active root already has the name. Drop the old copy only when it is
                // byte for byte the same file, so differing content is never thrown away.
                if (self::sameFile($source, $target)) {
                    @unlink($source);
                }

                continue;
            }

            if (@rename($source, $target)) {
                continue;
            }

            // The copy is verified under a working name, so a reader never sees a half
            // copied file under the name the database points at.
            $working = self::workingName($target);

            if (! @copy($source, $working)) {
                @unlink($working);

                continue;
            }

            if (! self::sameFile($source, $working) || ! @rename($working, $target)) {
                @unlink($working);

                continue;
            }

            @unlink($source);
        }
    }

    private static function workingName(string $path): string
    {
        return $path . '.part-' . bin2hex(random_bytes(6));
    }

    private static function holdsPrivateFiles(string $directory): bool
    {
        foreach (self::privateNames($directory) as $name) {
            if (is_file($directory . '/' . $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function privateNames(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $names = scandir($directory);

        if (! is_array($names)) {
            return [];
        }

        $found = [];

        foreach ($names as $name) {
            if (is_string($name) && PrivateStorageLocation::isPrivateName($name)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    private static function sameFile(string $source, string $target): bool
    {
        $sourceSize = @filesize($source);
        $targetSize = @filesize($target);

        if (! is_int($sourceSize) || ! is_int($targetSize) || $sourceSize !== $targetSize) {
            return false;
        }

        $sourceHash = @hash_file('sha256', $source);
        $targetHash = @hash_file('sha256', $target);

        return is_string($sourceHash) && is_string($targetHash) && hash_equals($sourceHash, $targetHash);
    }
}
