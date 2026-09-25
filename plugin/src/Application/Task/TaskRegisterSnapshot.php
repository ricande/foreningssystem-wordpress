<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Task;

final class TaskRegisterSnapshot
{
    /**
     * @param list<TaskRegisterRow> $rows
     * @param list<array{id: int, name: string}> $assigneeChoices
     */
    public function __construct(
        public readonly int $openCount,
        public readonly int $overdueCount,
        public readonly int $doneCount,
        public readonly int $matched,
        public readonly int $page,
        public readonly int $pages,
        public readonly array $rows,
        public readonly array $assigneeChoices,
        public readonly TaskRegisterQuery $query,
    ) {
    }
}
