<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use Foreningssystem\Domain\Membership\AssociationDate;

interface OpenBoardAssignments
{
    public function endOpen(int $personId, AssociationDate $on): void;
}
