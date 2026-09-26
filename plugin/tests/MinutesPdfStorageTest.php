<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Infrastructure\WordPress\PrivateUploadDirectory;
use Foreningssystem\Infrastructure\WordPress\WpMinutesPdfStore;
use Foreningssystem\Tests\Support\WordPressStorage;
use PHPUnit\Framework\TestCase;

final class MinutesPdfStorageTest extends TestCase
{
    private const REVISION = 4;

    private ?string $root = null;

    private MinutesRevisionTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = PrivateStorageTree::create('minutes-pdf');
        WordPressStorage::useUploads($this->root . '/uploads');
        $this->table = new MinutesRevisionTable();
        $GLOBALS['wpdb'] = $this->table;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        WordPressStorage::reset();

        if (is_string($this->root)) {
            PrivateStorageTree::remove($this->root);
            $this->root = null;
        }

        parent::tearDown();
    }

    public function test_a_new_pdf_replaces_the_old_file_only_after_the_row_points_at_it(): void
    {
        $store = new WpMinutesPdfStore();
        $first = $this->hash('Föreningen köper modell X.');
        $second = $this->hash('Föreningen köper modell Y.');

        $store->put(self::REVISION, $first, 'gammal pdf');

        self::assertSame($this->fileName($first), $store->stored(self::REVISION)['name'] ?? null);
        self::assertFileExists($this->path($first));

        // While the row is being pointed at the new file, the new file is already complete
        // on disk and the old file is still there.
        $this->table->duringUpdate = function () use ($first, $second): void {
            self::assertFileExists($this->path($first));
            self::assertFileExists($this->path($second));
            self::assertSame('ny pdf', file_get_contents($this->path($second)));
        };

        $store->put(self::REVISION, $second, 'ny pdf');

        self::assertSame($this->fileName($second), $store->stored(self::REVISION)['name'] ?? null);
        self::assertSame('ny pdf', $store->read(self::REVISION));
        self::assertFileExists($this->path($second));
        self::assertFileDoesNotExist($this->path($first));
        self::assertSame([$this->fileName($second)], $this->storedFiles());
    }

    public function test_a_regeneration_that_cannot_be_recorded_keeps_the_working_pdf(): void
    {
        $store = new WpMinutesPdfStore();
        $first = $this->hash('Föreningen köper modell X.');
        $second = $this->hash('Föreningen köper modell Y.');

        $store->put(self::REVISION, $first, 'gammal pdf');
        $this->table->failUpdate = true;

        try {
            $store->put(self::REVISION, $second, 'ny pdf');
            self::fail('A failed row update should stop the regeneration.');
        } catch (\RuntimeException $error) {
            self::assertSame('The PDF reference could not be saved.', $error->getMessage());
        }

        self::assertSame($this->fileName($first), $store->stored(self::REVISION)['name'] ?? null);
        self::assertSame('gammal pdf', $store->read(self::REVISION));
        self::assertFileExists($this->path($first));
        self::assertFileDoesNotExist($this->path($second));
        self::assertSame([$this->fileName($first)], $this->storedFiles());
    }

    public function test_a_row_that_cannot_be_confirmed_removes_no_pdf_at_all(): void
    {
        $store = new WpMinutesPdfStore();
        $first = $this->hash('Föreningen köper modell X.');
        $second = $this->hash('Föreningen köper modell Y.');

        $store->put(self::REVISION, $first, 'gammal pdf');
        $this->table->hideAfterUpdate = true;

        try {
            $store->put(self::REVISION, $second, 'ny pdf');
            self::fail('An unreadable row should stop the regeneration.');
        } catch (\RuntimeException $error) {
            self::assertSame('The PDF reference could not be confirmed.', $error->getMessage());
        }

        self::assertFileExists($this->path($first));
        self::assertFileExists($this->path($second));
        self::assertSame('gammal pdf', file_get_contents($this->path($first)));
    }

    public function test_writing_the_same_pdf_again_removes_nothing(): void
    {
        $store = new WpMinutesPdfStore();
        $hash = $this->hash('Föreningen köper modell X.');

        $store->put(self::REVISION, $hash, 'pdf');
        $store->put(self::REVISION, $hash, 'pdf');

        self::assertSame('pdf', $store->read(self::REVISION));
        self::assertSame([$this->fileName($hash)], $this->storedFiles());
    }

    public function test_a_row_that_points_at_another_kind_of_private_file_is_left_alone(): void
    {
        $store = new WpMinutesPdfStore();
        $signed = 'signed-' . self::REVISION . '-' . $this->hash('skannad kopia') . '.pdf';
        $this->table->rows[self::REVISION] = ['name' => $signed, 'hash' => $this->hash('gammal text')];
        file_put_contents(PrivateUploadDirectory::path() . '/' . $signed, 'skannad kopia');

        $store->put(self::REVISION, $this->hash('ny text'), 'ny pdf');

        self::assertFileExists(PrivateUploadDirectory::path() . '/' . $signed);
        self::assertSame('skannad kopia', file_get_contents(PrivateUploadDirectory::path() . '/' . $signed));
    }

    public function test_a_pdf_stored_in_an_earlier_root_is_still_read_and_replaced(): void
    {
        $earlier = (string) $this->root . '/private-a';
        self::assertTrue(mkdir($earlier, 0o755, true));
        WordPressStorage::$configured = $earlier;

        $store = new WpMinutesPdfStore();
        $first = $this->hash('Föreningen köper modell X.');
        $store->put(self::REVISION, $first, 'gammal pdf');
        self::assertFileExists($earlier . '/' . $this->fileName($first));

        // The directory becomes unreachable, so the file stays where it is and the root is
        // recorded. The next request writes to the fallback again.
        chmod($earlier . '/' . $this->fileName($first), 0o000);
        chmod($earlier, 0o555);

        if (is_writable($earlier)) {
            chmod($earlier, 0o755);
            chmod($earlier . '/' . $this->fileName($first), 0o644);
            self::markTestSkipped('The test process ignores directory permissions.');
        }

        WordPressStorage::$configured = null;
        $second = $this->hash('Föreningen köper modell Y.');
        $store->put(self::REVISION, $second, 'ny pdf');

        self::assertSame('ny pdf', $store->read(self::REVISION));
        self::assertSame([$earlier], WordPressStorage::$options[PrivateUploadDirectory::EARLIER_ROOTS_OPTION] ?? []);
        // The old file could not be removed, and the new one is the one the row points at.
        self::assertFileExists($earlier . '/' . $this->fileName($first));

        chmod($earlier, 0o755);
        chmod($earlier . '/' . $this->fileName($first), 0o644);
    }

    /**
     * @return list<string>
     */
    private function storedFiles(): array
    {
        $names = scandir(PrivateUploadDirectory::path());
        $found = [];

        foreach (is_array($names) ? $names : [] as $name) {
            if (str_starts_with((string) $name, 'revision-')) {
                $found[] = (string) $name;
            }
        }

        return $found;
    }

    private function path(string $hash): string
    {
        return PrivateUploadDirectory::path() . '/' . $this->fileName($hash);
    }

    private function fileName(string $hash): string
    {
        return 'revision-' . self::REVISION . '-' . $hash . '.pdf';
    }

    private function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}

/**
 * The minutes revision table, with the two columns the PDF store reads and writes.
 */
final class MinutesRevisionTable
{
    public string $prefix = 'wp_';

    public bool $failUpdate = false;

    public bool $hideAfterUpdate = false;

    /** Runs while the row is being updated, so a test can look at the files on disk. */
    public ?\Closure $duringUpdate = null;

    /** @var array<int, array{name: string, hash: string}> */
    public array $rows = [];

    private bool $hidden = false;

    public function prepare(string $query, mixed ...$arguments): string
    {
        return (string) json_encode(['query' => $query, 'arguments' => $arguments]);
    }

    /**
     * @return array<string, string>|null
     */
    public function get_row(string $query, string $output = 'OBJECT'): ?array
    {
        unset($output);
        $call = $this->decode($query);
        $id = (int) ($call['arguments'][0] ?? 0);

        if ($this->hidden || ! isset($this->rows[$id])) {
            return null;
        }

        return [
            'pdf_storage_name' => $this->rows[$id]['name'],
            'pdf_source_hash' => $this->rows[$id]['hash'],
        ];
    }

    public function query(string $query): int|bool
    {
        $call = $this->decode($query);
        [$name, $hash, $id] = [(string) $call['arguments'][0], (string) $call['arguments'][1], (int) $call['arguments'][2]];

        if ($this->failUpdate) {
            return false;
        }

        if ($this->duringUpdate instanceof \Closure) {
            ($this->duringUpdate)();
        }

        $current = $this->rows[$id] ?? null;
        $this->rows[$id] = ['name' => $name, 'hash' => $hash];

        if ($this->hideAfterUpdate) {
            $this->hidden = true;
        }

        return $current !== null && $current['name'] === $name && $current['hash'] === $hash ? 0 : 1;
    }

    /**
     * @return array{query: string, arguments: array<int, mixed>}
     */
    private function decode(string $query): array
    {
        $call = json_decode($query, true);

        if (! is_array($call) || ! is_string($call['query'] ?? null) || ! is_array($call['arguments'] ?? null)) {
            throw new \RuntimeException('The store did not use $wpdb->prepare().');
        }

        return ['query' => $call['query'], 'arguments' => $call['arguments']];
    }
}
