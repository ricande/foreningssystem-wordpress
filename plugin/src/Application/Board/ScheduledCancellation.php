<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Board;

final class ScheduledCancellation
{
    public function __construct(
        private readonly string $roleSlug,
        private readonly string $roleName,
        private readonly ?string $currentEnd,
    ) {
    }

    public function roleSlug(): string
    {
        return $this->roleSlug;
    }

    public function roleName(): string
    {
        return $this->roleName;
    }

    public function currentEnd(): ?string
    {
        return $this->currentEnd;
    }
}
