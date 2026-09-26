<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use InvalidArgumentException;

final class UploadedFile
{
    /**
     * The same ceiling the domain file types accept. Checked here so a large upload is
     * rejected before it is read into memory.
     */
    public const MAX_BYTES = 8388608;

    public static function bytes(string $key, string $failure, int $maxBytes = self::MAX_BYTES): string
    {
        $file = $_FILES[$key] ?? null;

        if (! is_array($file) || ! isset($file['tmp_name'], $file['error'], $file['size']) || ! is_string($file['tmp_name'])) {
            throw new InvalidArgumentException($failure);
        }

        if (! is_numeric($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($failure);
        }

        $path = $file['tmp_name'];

        if ($path === '' || ! is_uploaded_file($path)) {
            throw new InvalidArgumentException($failure);
        }

        if (! is_numeric($file['size'])) {
            throw new InvalidArgumentException($failure);
        }

        $declared = (int) $file['size'];

        if ($declared < 1 || $declared > $maxBytes) {
            throw new InvalidArgumentException($failure);
        }

        clearstatcache(true, $path);
        $actual = filesize($path);

        if (! is_int($actual) || $actual < 1 || $actual > $maxBytes) {
            throw new InvalidArgumentException($failure);
        }

        // One byte past the limit, so a file that grew after the check is still rejected.
        $bytes = file_get_contents($path, false, null, 0, $maxBytes + 1);

        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > $maxBytes) {
            throw new InvalidArgumentException($failure);
        }

        return $bytes;
    }
}
