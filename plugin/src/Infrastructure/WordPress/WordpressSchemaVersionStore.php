<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Infrastructure\Persistence\SchemaVersionStore;

final class WordpressSchemaVersionStore implements SchemaVersionStore
{
    public const OPTION = 'assoc_schema_version';

    public function current(): int
    {
        $value = get_option(self::OPTION, 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function store(int $version): void
    {
        update_option(self::OPTION, $version);
    }
}
