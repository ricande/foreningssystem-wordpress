<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Association;

use Foreningssystem\Domain\Membership\AssociationDate;

final class MembershipYear
{
    public function __construct(
        private readonly AssociationDate $startedOn,
        private readonly AssociationDate $endedOn,
    ) {
    }

    public function startedOn(): AssociationDate
    {
        return $this->startedOn;
    }

    public function endedOn(): AssociationDate
    {
        return $this->endedOn;
    }
}
