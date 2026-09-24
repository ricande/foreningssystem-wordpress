<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Access;

final class MinutesLockEvent
{
    public function __construct(
        private readonly int $roleId,
        private readonly string $action,
    ) {
    }

    public function roleId(): int
    {
        return $this->roleId;
    }

    public function action(): string
    {
        return $this->action;
    }
}
