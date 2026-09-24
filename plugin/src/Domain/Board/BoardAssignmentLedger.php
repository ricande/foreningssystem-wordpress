<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Board;

use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipPeriod;

final class BoardAssignmentLedger
{
    /**
     * @param list<BoardAssignment> $existingForRole
     * @param list<MembershipPeriod> $memberships
     */
    public function add(array $existingForRole, BoardRole $role, BoardAssignment $candidate, array $memberships): void
    {
        if ($role->id() === null || $candidate->roleId() !== $role->id()) {
            throw new \InvalidArgumentException('The assignment does not match the role.');
        }

        $covered = false;

        foreach ($memberships as $period) {
            if ($period->personId() === $candidate->personId() && $period->coversAssignment($candidate->startedOn(), $candidate->endedOn())) {
                $covered = true;
                break;
            }
        }

        if (! $covered) {
            throw new BoardRuleException('The membership does not cover this assignment.');
        }

        if ($role->allowsMultiple()) {
            return;
        }

        foreach ($existingForRole as $assignment) {
            if ($assignment->overlaps($candidate)) {
                throw new BoardRuleException('This role already has a holder for those dates.');
            }
        }
    }

    public function end(BoardAssignment $assignment, AssociationDate $on): BoardAssignment
    {
        if ($assignment->endedOn() instanceof AssociationDate) {
            throw new BoardRuleException('The assignment is already ended.');
        }

        if ($on->isBefore($assignment->startedOn())) {
            throw new BoardRuleException('An assignment cannot end before it starts.');
        }

        return $assignment->withEnd($on);
    }
}
