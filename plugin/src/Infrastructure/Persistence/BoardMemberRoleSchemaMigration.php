<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

/**
 * Adds the ordinary board-member seat for installs that already passed schema 3.
 * Fresh installs receive the same row from BoardSchemaMigration. The insert skips
 * a slug that is already there. No table changes.
 */
final class BoardMemberRoleSchemaMigration implements Migration
{
    public function __construct(
        private readonly string $prefix,
    ) {
    }

    public function version(): int
    {
        return 17;
    }

    public function up(): void
    {
        (new BoardSchemaMigration($this->prefix, ''))->ensureSuggestedRoles();
    }
}
