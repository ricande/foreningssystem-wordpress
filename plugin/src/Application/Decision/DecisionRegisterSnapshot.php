<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Decision;

final class DecisionRegisterSnapshot
{
    /**
     * @param list<DecisionRegisterRow> $rows
     * @param list<array{id: int, name: string}> $responsibleChoices
     */
    public function __construct(
        public readonly int $openCount,
        public readonly int $overdueCount,
        public readonly int $doneCount,
        public readonly int $matched,
        public readonly int $page,
        public readonly int $pages,
        public readonly array $rows,
        public readonly array $responsibleChoices,
        public readonly DecisionRegisterQuery $query,
    ) {
    }
}
