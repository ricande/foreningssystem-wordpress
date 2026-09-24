<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

/**
 * Member coverage that includes a date and continues until an inclusive end.
 * A missing end means the coverage is still open.
 */
final class CoverageSpan
{
    public function __construct(private readonly ?AssociationDate $endsOn)
    {
    }

    public function endsOn(): ?AssociationDate
    {
        return $this->endsOn;
    }

    public function isOpenEnded(): bool
    {
        return ! $this->endsOn instanceof AssociationDate;
    }
}
