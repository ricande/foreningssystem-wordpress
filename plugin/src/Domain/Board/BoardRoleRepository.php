<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Board;

interface BoardRoleRepository
{
    public function add(BoardRole $role): BoardRole;

    public function save(BoardRole $role): void;

    public function find(int $id): ?BoardRole;

    public function findBySlug(string $slug): ?BoardRole;

    /**
     * @return list<BoardRole>
     */
    public function all(): array;
}
