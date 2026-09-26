<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Infrastructure\Files\PrivateStorageLocation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PrivateStorageLocationTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if (is_string($this->root)) {
            PrivateStorageTree::remove($this->root);
            $this->root = null;
        }

        parent::tearDown();
    }

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

    public function test_a_symlink_that_looks_external_but_leads_into_the_web_root_is_inside_it(): void
    {
        $root = $this->tree();
        $webRoot = $root . '/html';
        $inside = $webRoot . '/wp-content/uploads/assoc-private';
        $outside = $root . '/assoc-private';
        $link = $root . '/looks-external';

        self::assertTrue(symlink($inside, $link));

        self::assertSame(PrivateStorageLocation::OUTSIDE_WEB_ROOT, PrivateStorageLocation::placementOf($outside, $webRoot));
        self::assertSame(PrivateStorageLocation::INSIDE_WEB_ROOT, PrivateStorageLocation::placementOf($inside, $webRoot));
        self::assertSame(PrivateStorageLocation::INSIDE_WEB_ROOT, PrivateStorageLocation::placementOf($link, $webRoot));

        // A directory that does not exist yet under that symlink is inside the web root too.
        self::assertSame(PrivateStorageLocation::INSIDE_WEB_ROOT, PrivateStorageLocation::placementOf($link . '/later', $webRoot));

        // `..` out of a symlinked directory lands inside the web root as well.
        self::assertSame(PrivateStorageLocation::INSIDE_WEB_ROOT, PrivateStorageLocation::placementOf($link . '/../assoc-private', $webRoot));
    }

    public function test_a_symlinked_web_root_still_contains_its_own_upload_directory(): void
    {
        $root = $this->tree();
        $webRoot = $root . '/html';
        $inside = $webRoot . '/wp-content/uploads/assoc-private';
        $servedFrom = $root . '/current';

        self::assertTrue(symlink($webRoot, $servedFrom));

        self::assertSame(PrivateStorageLocation::INSIDE_WEB_ROOT, PrivateStorageLocation::placementOf($inside, $servedFrom));
        self::assertSame(PrivateStorageLocation::INSIDE_WEB_ROOT, PrivateStorageLocation::placementOf($servedFrom . '/wp-content/uploads/assoc-private', $webRoot));
    }

    public function test_choosing_a_symlinked_directory_records_the_resolved_path_and_the_placement(): void
    {
        $root = $this->tree();
        $webRoot = $root . '/html';
        $inside = $webRoot . '/wp-content/uploads/assoc-private';
        $fallback = $webRoot . '/wp-content/uploads/assoc-private-fallback';
        $link = $root . '/looks-external';

        self::assertTrue(symlink($inside, $link));

        $chosen = PrivateStorageLocation::choose(
            $webRoot,
            $link,
            $fallback,
            static fn (string $directory): bool => is_dir($directory) || mkdir($directory, 0o755, true)
        );

        self::assertSame($inside, $chosen->directory());
        self::assertTrue($chosen->isInsideWebRoot());
    }

    public function test_a_path_that_does_not_exist_keeps_its_spelled_out_placement(): void
    {
        $root = $this->tree();
        $webRoot = $root . '/html';

        self::assertSame(PrivateStorageLocation::OUTSIDE_WEB_ROOT, PrivateStorageLocation::placementOf($root . '/nothing-here', $webRoot));
        self::assertSame(PrivateStorageLocation::INSIDE_WEB_ROOT, PrivateStorageLocation::placementOf($webRoot . '/nothing-here', $webRoot));
        self::assertSame($root . '/nothing-here', PrivateStorageLocation::canonical($root . '/nothing-here'));
        self::assertSame('', PrivateStorageLocation::canonical(''));
        self::assertSame('/', PrivateStorageLocation::canonical('/'));
    }

    private function tree(): string
    {
        $root = PrivateStorageTree::create('placement');
        $this->root = $root;

        self::assertTrue(mkdir($root . '/html/wp-content/uploads/assoc-private', 0o755, true));
        self::assertTrue(mkdir($root . '/assoc-private', 0o755, true));

        return $root;
    }
}

final class PrivateStorageTree
{
    public static function create(string $name): string
    {
        $root = sys_get_temp_dir() . '/foreningsplugin-' . $name . '-' . bin2hex(random_bytes(6));

        if (! mkdir($root, 0o755, true)) {
            throw new RuntimeException('The test directory could not be created.');
        }

        $real = realpath($root);

        return is_string($real) ? $real : $root;
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $names = scandir($path);

        foreach (is_array($names) ? $names : [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            self::remove($path . '/' . $name);
        }

        rmdir($path);
    }
}
