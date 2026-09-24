<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Board;

use Foreningssystem\Application\People\OpenBoardAssignments;
use Foreningssystem\Domain\Board\BoardAssignmentLedger;
use Foreningssystem\Domain\Board\BoardAssignmentRepository;
use Foreningssystem\Domain\Membership\AssociationDate;

final class EndOpenBoardAssignments implements OpenBoardAssignments
{
    public function __construct(
        private readonly BoardAssignmentRepository $assignments,
        private readonly BoardAssignmentLedger $ledger,
    ) {
    }

    public function endOpen(int $personId, AssociationDate $on): void
    {
        foreach ($this->assignments->all() as $assignment) {
            if ($assignment->personId() !== $personId || $assignment->endedOn() instanceof AssociationDate) {
                continue;
            }

            $this->assignments->save($this->ledger->end($assignment, $on));
        }
    }
}
