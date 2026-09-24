<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Infrastructure\Files\PrivateStorageLocation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PrivateStorageLocationTest extends TestCase
{
    public function test_a_directory_under_the_web_root_is_the_fallback_and_an_outside_directory_is_not(): void
    {
        $webRoot = '/var/www/html';
        $fallback = '/var/www/html/wp-content/uploads/assoc-private';
        $outside = '/var/assoc-private';
        $usable = [];
        $choose = static function (string $directory) use (&$usable): bool {
            return in_array($directory, $usable, true);
        };

        self::assertSame(PrivateStorageLocation::INSIDE_WEB_ROOT, PrivateStorageLocation::placementOf($fallback, $webRoot));
        self::assertSame(PrivateStorageLocation::OUTSIDE_WEB_ROOT, PrivateStorageLocation::placementOf($outside, $webRoot));
        self::assertSame(PrivateStorageLocation::OUTSIDE_WEB_ROOT, PrivateStorageLocation::placementOf('/var/www/html/../assoc-private', $webRoot));

        $usable = [$outside, $fallback];
        $chosen = PrivateStorageLocation::choose($webRoot, $outside, $fallback, $choose);
        self::assertSame($outside, $chosen->directory());
        self::assertFalse($chosen->isInsideWebRoot());

        $usable = [$fallback];
        $fallbackOnly = PrivateStorageLocation::choose($webRoot, $outside, $fallback, $choose);
        self::assertSame($fallback, $fallbackOnly->directory());
        self::assertTrue($fallbackOnly->isInsideWebRoot());

        $insideConfigured = PrivateStorageLocation::choose($webRoot, $fallback . '/custom', $fallback, static fn (string $directory): bool => true);
        self::assertTrue($insideConfigured->isInsideWebRoot());

        $usable = [];

        $this->expectException(RuntimeException::class);
        PrivateStorageLocation::choose($webRoot, $outside, $fallback, $choose);
    }
}
