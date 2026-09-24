<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Membership\AssociationDate;

interface OpenBoardAssignments
{
    public function endOpen(int $personId, AssociationDate $on): void;

    /**
     * @return list<BoardAssignment>
     */
    public function assignmentsFor(int $personId): array;

    public function endAssignment(BoardAssignment $assignment, AssociationDate $on): void;
}
