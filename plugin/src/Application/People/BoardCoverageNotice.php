<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

/**
 * What happened to one board assignment after membership coverage changed.
 */
final class BoardCoverageNotice
{
    public function __construct(
        private readonly int $personId,
        private readonly int $roleId,
        private readonly bool $remainsActive,
        private readonly ?string $endedOn,
    ) {
    }

    public function personId(): int
    {
        return $this->personId;
    }

    public function roleId(): int
    {
        return $this->roleId;
    }

    public function remainsActive(): bool
    {
        return $this->remainsActive;
    }

    public function endedOn(): ?string
    {
        return $this->endedOn;
    }
}
