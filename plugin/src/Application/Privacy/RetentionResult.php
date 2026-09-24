<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Privacy;

final class RetentionResult
{
    public function __construct(
        private readonly int $anonymized,
        private readonly int $removedAudits,
    ) {
    }

    public function anonymized(): int
    {
        return $this->anonymized;
    }

    public function removedAudits(): int
    {
        return $this->removedAudits;
    }
}
