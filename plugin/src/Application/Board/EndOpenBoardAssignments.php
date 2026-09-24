<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Board;

use Foreningssystem\Application\People\OpenBoardAssignments;
use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardAssignmentLedger;
use Foreningssystem\Domain\Board\BoardAssignmentRepository;
use Foreningssystem\Domain\Board\BoardRuleException;
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

    public function assignmentsFor(int $personId): array
    {
        $rows = [];

        foreach ($this->assignments->all() as $assignment) {
            if ($assignment->personId() === $personId) {
                $rows[] = $assignment;
            }
        }

        return $rows;
    }

    public function endAssignment(BoardAssignment $assignment, AssociationDate $on): void
    {
        if ($on->isBefore($assignment->startedOn())) {
            throw new BoardRuleException('An assignment cannot end before it starts.');
        }

        $current = $assignment->endedOn();

        if ($current instanceof AssociationDate) {
            if (! $on->isBefore($current)) {
                return;
            }

            $this->assignments->save($assignment->withEnd($on));

            return;
        }

        $this->assignments->save($this->ledger->end($assignment, $on));
    }
}
