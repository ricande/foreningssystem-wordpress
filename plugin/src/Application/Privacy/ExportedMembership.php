<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Privacy;

final class ExportedMembership
{
    public function __construct(
        private readonly int $id,
        private readonly string $number,
        private readonly string $type,
        private readonly string $status,
        private readonly string $startedOn,
        private readonly ?string $endedOn,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function startedOn(): string
    {
        return $this->startedOn;
    }

    public function endedOn(): ?string
    {
        return $this->endedOn;
    }
}
