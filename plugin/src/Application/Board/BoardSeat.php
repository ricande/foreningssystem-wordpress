<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Board;

final class BoardSeat
{
    public function __construct(
        private readonly int $assignmentId,
        private readonly int $personId,
        private readonly string $personName,
        private readonly int $roleId,
        private readonly string $roleSlug,
        private readonly string $roleName,
        private readonly int $sortOrder,
        private readonly bool $allowsMultiple,
        private readonly string $startedOn,
        private readonly ?string $endedOn,
        private readonly string $termLabel,
        private readonly string $publicContact,
        private readonly string $state,
    ) {
    }

    public function assignmentId(): int
    {
        return $this->assignmentId;
    }

    public function personId(): int
    {
        return $this->personId;
    }

    public function personName(): string
    {
        return $this->personName;
    }

    public function roleId(): int
    {
        return $this->roleId;
    }

    public function roleSlug(): string
    {
        return $this->roleSlug;
    }

    public function roleName(): string
    {
        return $this->roleName;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function allowsMultiple(): bool
    {
        return $this->allowsMultiple;
    }

    public function startedOn(): string
    {
        return $this->startedOn;
    }

    public function endedOn(): ?string
    {
        return $this->endedOn;
    }

    public function termLabel(): string
    {
        return $this->termLabel;
    }

    public function publicContact(): string
    {
        return $this->publicContact;
    }

    public function state(): string
    {
        return $this->state;
    }
}
