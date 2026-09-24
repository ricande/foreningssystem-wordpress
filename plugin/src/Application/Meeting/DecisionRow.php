<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use Foreningssystem\Domain\Meeting\Decision;

final class DecisionRow
{
    public function __construct(
        private readonly Decision $decision,
        private readonly ?string $responsibleName,
    ) {
    }

    public function decision(): Decision
    {
        return $this->decision;
    }

    public function responsibleName(): ?string
    {
        return $this->responsibleName;
    }
}
