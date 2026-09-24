<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

/**
 * The dates where one participation interval and one membership period both apply.
 */
final class EffectiveCoverage
{
    public function __construct(
        private readonly int $membershipId,
        private readonly AssociationDate $startedOn,
        private readonly ?AssociationDate $endedOn,
    ) {
    }

    public function membershipId(): int
    {
        return $this->membershipId;
    }

    public function startedOn(): AssociationDate
    {
        return $this->startedOn;
    }

    public function endedOn(): ?AssociationDate
    {
        return $this->endedOn;
    }

    public function includes(AssociationDate $date): bool
    {
        if ($date->isBefore($this->startedOn)) {
            return false;
        }

        if ($this->endedOn instanceof AssociationDate && $date->isAfter($this->endedOn)) {
            return false;
        }

        return true;
    }
}
