<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

use InvalidArgumentException;

final class SignedCopyType
{
    public const PDF = 'application/pdf';

    public const JPEG = 'image/jpeg';

    public const PNG = 'image/png';

    public static function fromBytes(string $bytes): string
    {
        if (strlen($bytes) < 8 || strlen($bytes) > 8388608) {
            throw new InvalidArgumentException('A signed copy must be a PDF, JPEG, or PNG.');
        }

        if (str_starts_with($bytes, '%PDF')) {
            return self::PDF;
        }

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return self::JPEG;
        }

        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return self::PNG;
        }

        throw new InvalidArgumentException('A signed copy must be a PDF, JPEG, or PNG.');
    }

    public static function extension(string $mediaType): string
    {
        return match ($mediaType) {
            self::PDF => 'pdf',
            self::JPEG => 'jpg',
            self::PNG => 'png',
            default => throw new InvalidArgumentException('A signed copy must be a PDF, JPEG, or PNG.'),
        };
    }
}
