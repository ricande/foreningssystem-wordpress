<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Privacy;

final class EraseOutcome
{
    public function __construct(
        private readonly int $personId,
        private readonly bool $identifiersCleared,
        private readonly bool $publicContactCleared,
        private readonly bool $membershipRetained,
        private readonly bool $assignmentRetained,
        private readonly bool $minutesNameRetained,
        private readonly bool $signedCopyRetained,
    ) {
    }

    public function personId(): int
    {
        return $this->personId;
    }

    public function identifiersCleared(): bool
    {
        return $this->identifiersCleared;
    }

    public function publicContactCleared(): bool
    {
        return $this->publicContactCleared;
    }

    public function membershipRetained(): bool
    {
        return $this->membershipRetained;
    }

    public function assignmentRetained(): bool
    {
        return $this->assignmentRetained;
    }

    public function minutesNameRetained(): bool
    {
        return $this->minutesNameRetained;
    }

    public function signedCopyRetained(): bool
    {
        return $this->signedCopyRetained;
    }
}
