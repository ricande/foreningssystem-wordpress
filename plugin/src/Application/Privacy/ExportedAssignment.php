<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Privacy;

final class ExportedAssignment
{
    public function __construct(
        private readonly int $id,
        private readonly string $roleName,
        private readonly string $startedOn,
        private readonly ?string $endedOn,
        private readonly string $publicContact,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function roleName(): string
    {
        return $this->roleName;
    }

    public function startedOn(): string
    {
        return $this->startedOn;
    }

    public function endedOn(): ?string
    {
        return $this->endedOn;
    }

    public function publicContact(): string
    {
        return $this->publicContact;
    }
}
