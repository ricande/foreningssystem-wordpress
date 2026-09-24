<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Board;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardAssignmentLedger;
use Foreningssystem\Domain\Board\BoardAssignmentRepository;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Board\BoardRoleRepository;
use Foreningssystem\Domain\Board\BoardRuleException;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MemberCoverage;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;
use Foreningssystem\Domain\Person\PersonStatus;

final class BoardService
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly MembershipRepository $memberships,
        private readonly BoardRoleRepository $roles,
        private readonly BoardAssignmentRepository $assignments,
        private readonly BoardAssignmentLedger $ledger,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
    ) {
    }

    public function place(
        int $personId,
        int $roleId,
        AssociationDate $startedOn,
        ?AssociationDate $endedOn,
        string $publicContact,
        string $termLabel,
        ?AssociationDate $today = null,
    ): string {
        $this->require(Capabilities::MANAGE_BOARD);
        $asOf = $today ?? $startedOn;

        return $this->transaction->run(function () use ($personId, $roleId, $startedOn, $endedOn, $publicContact, $termLabel, $asOf): string {
            $person = $this->requirePerson($personId);
            $role = $this->requireRole($roleId);

            if ($person->status() === PersonStatus::Deceased && ! $endedOn instanceof AssociationDate) {
                throw new BoardRuleException('A deceased person cannot hold an open assignment.');
            }

            $participants = $this->memberships->allParticipants();
            $periods = $this->memberships->all();
            $covered = MemberCoverage::coversContinuousAssignment($personId, $startedOn, $endedOn, $participants, $periods);
            $candidate = new BoardAssignment(
                null,
                $personId,
                $roleId,
                $startedOn,
                $endedOn,
                trim($publicContact),
                trim($termLabel)
            );
            $checked = [];
            $replacements = [];
            $openSuccessor = ! $endedOn instanceof AssociationDate && ! $role->allowsMultiple();

            if ($openSuccessor) {
                foreach ($this->forRole($roleId) as $existing) {
                    if ($existing->startedOn()->isAfter($asOf)) {
                        throw new BoardRuleException('This role already has a scheduled assignment.');
                    }
                }
            }

            foreach ($this->forRole($roleId) as $existing) {
                if (! $openSuccessor || ! $this->canShorten($existing, $candidate, $asOf)) {
                    $checked[] = $existing;
                    continue;
                }

                $on = $startedOn->previousDay();

                if ($on->isBefore($existing->startedOn())) {
                    throw new BoardRuleException('An assignment cannot end before it starts.');
                }

                if ($existing->endedOn() instanceof AssociationDate && ! $on->isBefore($existing->endedOn())) {
                    $checked[] = $existing;
                    continue;
                }

                $ended = $existing->endedOn() instanceof AssociationDate
                    ? $existing->withEnd($on)
                    : $this->ledger->end($existing, $on);
                $checked[] = $ended;
                $replacements[] = $ended;
            }

            if (! $role->allowsMultiple()) {
                foreach ($checked as $existing) {
                    if (! $existing->overlaps($candidate)) {
                        continue;
                    }

                    if ($existing->startedOn()->isAfter($asOf)) {
                        throw new BoardRuleException('This role already has a scheduled assignment.');
                    }

                    throw new BoardRuleException('This role already has a holder for those dates.');
                }
            }

            $this->ledger->add($checked, $role, $candidate, $covered);

            foreach ($replacements as $replacement) {
                $this->assignments->save($replacement);
            }

            $this->assignments->add($candidate);

            return $replacements === [] ? 'saved' : 'replaced';
        });
    }

    public function cancelScheduled(int $assignmentId, AssociationDate $today): ScheduledCancellation
    {
        $this->require(Capabilities::MANAGE_BOARD);
        $assignment = $this->requireAssignment($assignmentId);

        if (! $assignment->startedOn()->isAfter($today)) {
            throw new BoardRuleException('Only a scheduled assignment that has not started can be cancelled.');
        }

        $role = $this->requireRole($assignment->roleId());
        $currentEnd = null;

        foreach ($this->forRole($assignment->roleId()) as $other) {
            if ($other->id() === $assignment->id() || ! $other->covers($today)) {
                continue;
            }

            $currentEnd = $other->endedOn()?->iso();
        }

        $result = new ScheduledCancellation($role->slug(), $role->name(), $currentEnd);
        $id = (int) $assignment->id();

        $this->transaction->run(function () use ($id): void {
            $this->assignments->remove($id);
        });

        return $result;
    }

    public function end(int $assignmentId, AssociationDate $on): void
    {
        $this->require(Capabilities::MANAGE_BOARD);
        $assignment = $this->requireAssignment($assignmentId);

        $this->transaction->run(function () use ($assignment, $on): void {
            $this->assignments->save($this->ledger->end($assignment, $on));
        });
    }

    /**
     * @return list<BoardPost>
     */
    public function history(AssociationDate $today): array
    {
        $this->require(Capabilities::VIEW_MEMBERS);
        $posts = [];

        foreach ($this->assignments->all() as $assignment) {
            $person = $this->people->find($assignment->personId());
            $role = $this->roles->find($assignment->roleId());

            if (! $person instanceof Person || ! $role instanceof BoardRole) {
                continue;
            }

            $current = $person->status() !== PersonStatus::Deceased && $assignment->covers($today);
            $posts[] = new BoardPost(
                $assignment,
                $person->firstName() . ' ' . $person->lastName(),
                $role->name(),
                $role->slug(),
                $current
            );
        }

        return $posts;
    }

    public function currentCount(AssociationDate $today): int
    {
        $count = 0;

        foreach ($this->history($today) as $post) {
            if ($post->current()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<PublicBoardSeat>
     */
    public function currentPublic(AssociationDate $today): array
    {
        $seats = [];

        foreach ($this->assignments->all() as $assignment) {
            if (! $assignment->covers($today)) {
                continue;
            }

            $person = $this->people->find($assignment->personId());
            $role = $this->roles->find($assignment->roleId());

            if (! $person instanceof Person || ! $role instanceof BoardRole || $person->status() === PersonStatus::Deceased) {
                continue;
            }

            $seats[] = new PublicBoardSeat(
                $person->firstName() . ' ' . $person->lastName(),
                $role->name(),
                $assignment->publicContact(),
                $role->sortOrder()
            );
        }

        usort(
            $seats,
            static function (PublicBoardSeat $left, PublicBoardSeat $right): int {
                $byOrder = $left->sortOrder() <=> $right->sortOrder();

                if ($byOrder !== 0) {
                    return $byOrder;
                }

                $byRole = strcasecmp($left->roleName(), $right->roleName());

                if ($byRole !== 0) {
                    return $byRole;
                }

                return strcasecmp($left->personName(), $right->personName());
            }
        );

        return $seats;
    }

    /**
     * @return list<BoardRole>
     */
    public function roles(): array
    {
        $this->require(Capabilities::VIEW_MEMBERS);

        return $this->roles->all();
    }

    private function canShorten(BoardAssignment $existing, BoardAssignment $candidate, AssociationDate $today): bool
    {
        if ($existing->endedOn() instanceof AssociationDate && $existing->endedOn()->isBefore($today)) {
            return false;
        }

        if (! $existing->startedOn()->isBefore($candidate->startedOn())) {
            return false;
        }

        return $existing->overlaps($candidate);
    }

    /**
     * @return list<BoardAssignment>
     */
    private function forRole(int $roleId): array
    {
        $assignments = [];

        foreach ($this->assignments->all() as $assignment) {
            if ($assignment->roleId() === $roleId) {
                $assignments[] = $assignment;
            }
        }

        return $assignments;
    }

    private function require(string $capability): void
    {
        if (! $this->authorizer->allows($capability)) {
            throw new NotAllowed($capability);
        }
    }

    private function requirePerson(int $personId): Person
    {
        $person = $this->people->find($personId);

        if (! $person instanceof Person) {
            throw new \RuntimeException('Person was not found.');
        }

        return $person;
    }

    private function requireRole(int $roleId): BoardRole
    {
        $role = $this->roles->find($roleId);

        if (! $role instanceof BoardRole || $role->id() === null) {
            throw new \RuntimeException('Role was not found.');
        }

        return $role;
    }

    private function requireAssignment(int $assignmentId): BoardAssignment
    {
        $assignment = $this->assignments->find($assignmentId);

        if (! $assignment instanceof BoardAssignment) {
            throw new \RuntimeException('Assignment was not found.');
        }

        return $assignment;
    }
}
