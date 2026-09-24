<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Board;

use Foreningssystem\Domain\Board\BoardAssignment;

final class BoardPost
{
    public function __construct(
        private readonly BoardAssignment $assignment,
        private readonly string $personName,
        private readonly string $roleName,
        private readonly string $roleSlug,
        private readonly bool $current,
    ) {
    }

    public function assignment(): BoardAssignment
    {
        return $this->assignment;
    }

    public function personName(): string
    {
        return $this->personName;
    }

    public function roleName(): string
    {
        return $this->roleName;
    }

    public function roleSlug(): string
    {
        return $this->roleSlug;
    }

    public function current(): bool
    {
        return $this->current;
    }
}
