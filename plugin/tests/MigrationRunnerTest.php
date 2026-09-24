<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Infrastructure\Persistence\Migration;
use Foreningssystem\Infrastructure\Persistence\MigrationException;
use Foreningssystem\Infrastructure\Persistence\MigrationLock;
use Foreningssystem\Infrastructure\Persistence\MigrationRunner;
use Foreningssystem\Infrastructure\Persistence\SchemaVersionStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigrationRunnerTest extends TestCase
{
    public function test_install_records_the_version_only_after_each_migration_succeeds(): void
    {
        $store = new MemorySchemaVersionStore();
        $first = new CountingMigration(1);
        $second = new CountingMigration(2);
        $runner = new MigrationRunner($store, new NullMigrationLock(), [$second, $first]);

        $runner->migrate();

        self::assertSame(1, $first->runs);
        self::assertSame(1, $second->runs);
        self::assertSame(2, $store->current());
    }

    public function test_second_run_does_not_repeat_completed_migrations(): void
    {
        $store = new MemorySchemaVersionStore();
        $migration = new CountingMigration(1);
        $runner = new MigrationRunner($store, new NullMigrationLock(), [$migration]);

        $runner->migrate();
        $runner->migrate();

        self::assertSame(1, $migration->runs);
        self::assertSame(1, $store->current());
    }

    public function test_failed_migration_does_not_advance_the_version_and_releases_the_lock(): void
    {
        $store = new MemorySchemaVersionStore();
        $lock = new CountingMigrationLock();
        $runner = new MigrationRunner($store, $lock, [
            new CountingMigration(1),
            new CountingMigration(2, new RuntimeException('boom')),
        ]);

        try {
            $runner->migrate();
            self::fail('The failed migration should throw.');
        } catch (RuntimeException $error) {
            self::assertSame('boom', $error->getMessage());
        }

        self::assertSame(1, $store->current());
        self::assertSame(1, $lock->acquired);
        self::assertSame(1, $lock->released);
    }

    public function test_a_gap_in_migration_versions_stops_before_that_migration(): void
    {
        $store = new MemorySchemaVersionStore();
        $skipped = new CountingMigration(3);
        $runner = new MigrationRunner($store, new NullMigrationLock(), [
            new CountingMigration(1),
            $skipped,
        ]);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Migration 3 does not follow schema version 1.');

        try {
            $runner->migrate();
        } finally {
            self::assertSame(0, $skipped->runs);
            self::assertSame(1, $store->current());
        }
    }

    public function test_duplicate_versions_are_rejected(): void
    {
        $runner = new MigrationRunner(new MemorySchemaVersionStore(), new NullMigrationLock(), [
            new CountingMigration(1),
            new CountingMigration(1),
        ]);

        $this->expectException(MigrationException::class);
        $runner->latestVersion();
    }
}

final class CountingMigration implements Migration
{
    public int $runs = 0;

    public function __construct(
        private readonly int $version,
        private readonly ?\Throwable $error = null,
    ) {
    }

    public function version(): int
    {
        return $this->version;
    }

    public function up(): void
    {
        $this->runs++;

        if ($this->error instanceof \Throwable) {
            throw $this->error;
        }
    }
}

final class MemorySchemaVersionStore implements SchemaVersionStore
{
    public function __construct(private int $version = 0)
    {
    }

    public function current(): int
    {
        return $this->version;
    }

    public function store(int $version): void
    {
        $this->version = $version;
    }
}

final class NullMigrationLock implements MigrationLock
{
    public function acquire(): void
    {
    }

    public function release(): void
    {
    }
}

final class CountingMigrationLock implements MigrationLock
{
    public int $acquired = 0;

    public int $released = 0;

    public function acquire(): void
    {
        $this->acquired++;
    }

    public function release(): void
    {
        $this->released++;
    }
}
