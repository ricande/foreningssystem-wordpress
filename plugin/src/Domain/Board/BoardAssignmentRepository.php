<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Board;

interface BoardAssignmentRepository
{
    public function add(BoardAssignment $assignment): BoardAssignment;

    public function save(BoardAssignment $assignment): void;

    public function find(int $id): ?BoardAssignment;

    /**
     * @return list<BoardAssignment>
     */
    public function all(): array;
}
