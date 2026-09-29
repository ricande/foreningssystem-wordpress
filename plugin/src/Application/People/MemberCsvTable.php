<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

final class MemberCsvTable
{
    /**
     * @param list<string> $headers
     * @param list<array{line: int, cells: list<string>, oversize: bool}> $rows
     */
    public function __construct(
        public readonly string $delimiter,
        public readonly array $headers,
        public readonly array $rows,
    ) {
    }
}
