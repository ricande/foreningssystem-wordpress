<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Infrastructure\Files\PrivateStorageRoots;
use PHPUnit\Framework\TestCase;

final class PrivateStorageRootsTest extends TestCase
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

    public function test_files_follow_the_storage_root_from_the_fallback_to_one_custom_directory_and_then_another(): void
    {
        $root = $this->tree();
        $fallback = $root . '/uploads/assoc-private';
        $first = $root . '/private-a';
        $second = $root . '/private-b';
        $document = $this->documentName('Stadgar 2026');
        $minutes = 'revision-4-' . hash('sha256', 'protokoll') . '.pdf';

        $onFallback = PrivateStorageRoots::settle($fallback, '', [], $fallback);
        self::assertSame($fallback, $onFallback->active());
        self::assertSame([], $onFallback->earlier());
        self::assertTrue($onFallback->write($document, 'Stadgar 2026'));
        self::assertTrue($onFallback->write($minutes, 'protokoll'));

        $onFirst = PrivateStorageRoots::settle($first, $fallback, [], $fallback);
        self::assertSame($first, $onFirst->active());
        self::assertSame([], $onFirst->earlier());
        self::assertSame($first . '/' . $document, $onFirst->locate($document));
        self::assertSame($first . '/' . $minutes, $onFirst->locate($minutes));
        self::assertFileDoesNotExist($fallback . '/' . $document);
        self::assertSame('Stadgar 2026', file_get_contents((string) $onFirst->locate($document)));

        $onSecond = PrivateStorageRoots::settle($second, $first, [], $fallback);
        self::assertSame($second, $onSecond->active());
        self::assertSame([], $onSecond->earlier());
        self::assertSame($second . '/' . $document, $onSecond->locate($document));
        self::assertSame($second . '/' . $minutes, $onSecond->locate($minutes));
        self::assertFileDoesNotExist($first . '/' . $document);
        self::assertFileDoesNotExist($first . '/' . $minutes);
        self::assertSame('Stadgar 2026', file_get_contents((string) $onSecond->locate($document)));
        self::assertSame('protokoll', file_get_contents((string) $onSecond->locate($minutes)));

        // Back to the fallback the same way.
        $back = PrivateStorageRoots::settle($fallback, $second, [], $fallback);
        self::assertSame($fallback . '/' . $document, $back->locate($document));
        self::assertSame('Stadgar 2026', file_get_contents((string) $back->locate($document)));
    }

    public function test_a_file_that_cannot_be_moved_keeps_its_root_in_the_state(): void
    {
        $root = $this->tree();
        $fallback = $root . '/uploads/assoc-private';
        $first = $root . '/private-a';
        $second = $root . '/private-b';
        $stuck = $this->documentName('Kan inte flyttas');

        $onFirst = PrivateStorageRoots::settle($first, $fallback, [], $fallback);
        self::assertTrue($onFirst->write($stuck, 'Kan inte flyttas'));

        chmod($first . '/' . $stuck, 0o000);
        chmod($first, 0o555);

        if (is_writable($first)) {
            chmod($first, 0o755);
            chmod($first . '/' . $stuck, 0o644);
            self::markTestSkipped('The test process ignores directory permissions.');
        }

        $onSecond = PrivateStorageRoots::settle($second, $first, [], $fallback);

        self::assertSame($second, $onSecond->active());
        self::assertSame([$first], $onSecond->earlier());
        self::assertSame($first . '/' . $stuck, $onSecond->locate($stuck));
        self::assertFileDoesNotExist($second . '/' . $stuck);

        // The state survives the next request, so the file stays reachable.
        $later = PrivateStorageRoots::settle($second, $second, $onSecond->earlier(), $fallback);
        self::assertSame([$first], $later->earlier());
        self::assertSame($first . '/' . $stuck, $later->locate($stuck));

        chmod($first, 0o755);
        chmod($first . '/' . $stuck, 0o644);
    }

    public function test_a_name_that_exists_in_both_roots_with_different_bytes_is_not_overwritten(): void
    {
        $root = $this->tree();
        $fallback = $root . '/uploads/assoc-private';
        $first = $root . '/private-a';
        $second = $root . '/private-b';
        $name = 'revision-7-' . hash('sha256', 'samma text') . '.pdf';

        $onFirst = PrivateStorageRoots::settle($first, $fallback, [], $fallback);
        self::assertTrue($onFirst->write($name, 'gammal utskrift'));

        $onSecond = PrivateStorageRoots::settle($second, '', [], $fallback);
        self::assertTrue($onSecond->write($name, 'ny utskrift'));

        $merged = PrivateStorageRoots::settle($second, $first, [], $fallback);

        self::assertSame([$first], $merged->earlier());
        self::assertSame('ny utskrift', file_get_contents($second . '/' . $name));
        self::assertSame('gammal utskrift', file_get_contents($first . '/' . $name));
        self::assertSame($second . '/' . $name, $merged->locate($name));
    }

    public function test_the_same_file_in_both_roots_is_kept_once_in_the_active_root(): void
    {
        $root = $this->tree();
        $fallback = $root . '/uploads/assoc-private';
        $first = $root . '/private-a';
        $name = $this->documentName('Samma bytes');

        $onFirst = PrivateStorageRoots::settle($first, $fallback, [], $fallback);
        self::assertTrue($onFirst->write($name, 'Samma bytes'));
        self::assertTrue(is_dir($fallback) || mkdir($fallback, 0o755, true));
        file_put_contents($fallback . '/' . $name, 'Samma bytes');

        $settled = PrivateStorageRoots::settle($first, $first, [$fallback], $fallback);

        self::assertSame([], $settled->earlier());
        self::assertFileDoesNotExist($fallback . '/' . $name);
        self::assertSame('Samma bytes', file_get_contents($first . '/' . $name));
    }

    public function test_a_recorded_root_that_is_unreachable_stays_in_the_state(): void
    {
        $root = $this->tree();
        $fallback = $root . '/uploads/assoc-private';
        $first = $root . '/private-a';
        $gone = $root . '/unmounted-volume';

        $settled = PrivateStorageRoots::settle($first, $gone, [], $fallback);

        self::assertSame([$gone], $settled->earlier());

        // The conventional fallback is not recorded, so an empty one is not carried along.
        self::assertSame([$gone], PrivateStorageRoots::settle($first, $first, $settled->earlier(), $fallback)->earlier());
    }

    public function test_a_write_that_fails_leaves_no_partial_file_behind(): void
    {
        $root = $this->tree();
        $fallback = $root . '/uploads/assoc-private';
        $name = $this->documentName('Kan inte skrivas');
        $state = PrivateStorageRoots::settle($fallback, '', [], $fallback);

        chmod($fallback, 0o555);

        if (is_writable($fallback)) {
            chmod($fallback, 0o755);
            self::markTestSkipped('The test process ignores directory permissions.');
        }

        self::assertFalse($state->write($name, 'Kan inte skrivas'));

        chmod($fallback, 0o755);

        self::assertSame([], glob($fallback . '/*') ?: []);
        self::assertNull($state->locate($name));

        // The bytes are stored under a working name and moved into place. When that move
        // cannot happen, the working file is cleaned up instead of being left in the root.
        self::assertTrue(mkdir($fallback . '/' . $name, 0o755));
        self::assertFalse($state->write($name, 'Kan inte skrivas'));
        self::assertSame([], glob($fallback . '/*.part-*') ?: []);
        self::assertNull($state->locate($name));
    }

    public function test_only_private_file_names_are_moved_read_or_written(): void
    {
        $root = $this->tree();
        $fallback = $root . '/uploads/assoc-private';
        $first = $root . '/private-a';

        $onFallback = PrivateStorageRoots::settle($fallback, '', [], $fallback);
        file_put_contents($fallback . '/index.php', "<?php\n");
        file_put_contents($fallback . '/notes.txt', 'lokalt');

        self::assertFalse($onFallback->write('../escape.pdf', 'nej'));
        self::assertFalse($onFallback->write('notes.txt', 'nej'));
        self::assertNull($onFallback->locate('notes.txt'));
        self::assertNull($onFallback->locate('index.php'));

        $onFirst = PrivateStorageRoots::settle($first, $fallback, [], $fallback);

        self::assertSame([], $onFirst->earlier());
        self::assertFileExists($fallback . '/index.php');
        self::assertFileExists($fallback . '/notes.txt');
        self::assertFileDoesNotExist($first . '/notes.txt');
    }

    private function documentName(string $bytes): string
    {
        return 'document-' . hash('sha256', $bytes) . '.pdf';
    }

    private function tree(): string
    {
        $root = PrivateStorageTree::create('roots');
        $this->root = $root;

        foreach (['/uploads/assoc-private', '/private-a', '/private-b'] as $directory) {
            self::assertTrue(mkdir($root . $directory, 0o755, true));
        }

        return $root;
    }
}
