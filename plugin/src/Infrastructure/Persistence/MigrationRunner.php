<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

final class MigrationRunner
{
    /**
     * @param list<Migration> $migrations
     */
    public function __construct(
        private readonly SchemaVersionStore $versions,
        private readonly MigrationLock $lock,
        private readonly array $migrations,
    ) {
    }

    public function latestVersion(): int
    {
        $this->assertValidList();

        $latest = 0;

        foreach ($this->migrations as $migration) {
            $latest = max($latest, $migration->version());
        }

        return $latest;
    }

    public function currentVersion(): int
    {
        return $this->versions->current();
    }

    public function migrate(): void
    {
        $this->assertValidList();
        $this->lock->acquire();

        try {
            $current = $this->versions->current();

            foreach ($this->ordered() as $migration) {
                if ($migration->version() <= $current) {
                    continue;
                }

                if ($migration->version() !== $current + 1) {
                    throw new MigrationException(sprintf(
                        'Migration %d does not follow schema version %d.',
                        $migration->version(),
                        $current
                    ));
                }

                $migration->up();
                $this->versions->store($migration->version());
                $current = $migration->version();
            }
        } finally {
            $this->lock->release();
        }
    }

    /**
     * @return list<Migration>
     */
    private function ordered(): array
    {
        $migrations = $this->migrations;

        usort(
            $migrations,
            static fn (Migration $left, Migration $right): int => $left->version() <=> $right->version()
        );

        return $migrations;
    }

    private function assertValidList(): void
    {
        $seen = [];

        foreach ($this->migrations as $migration) {
            $version = $migration->version();

            if ($version < 1) {
                throw new MigrationException('Migration versions must start at 1.');
            }

            if (isset($seen[$version])) {
                throw new MigrationException(sprintf('Migration version %d is declared twice.', $version));
            }

            $seen[$version] = true;
        }
    }
}
