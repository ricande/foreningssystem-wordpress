<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Board;

final class PublicBoardSeat
{
    public function __construct(
        private readonly string $personName,
        private readonly string $roleName,
        private readonly string $publicContact,
        private readonly int $sortOrder,
    ) {
    }

    public function personName(): string
    {
        return $this->personName;
    }

    public function roleName(): string
    {
        return $this->roleName;
    }

    public function publicContact(): string
    {
        return $this->publicContact;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
}
