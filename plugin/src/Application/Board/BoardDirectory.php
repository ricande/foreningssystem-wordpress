<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Board;

use Foreningssystem\Domain\Board\BoardAssignmentRepository;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Board\BoardRoleRepository;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MemberCoverage;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Person\PersonRepository;
use Foreningssystem\Domain\Person\PersonStatus;

final class BoardDirectory
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly MembershipRepository $memberships,
        private readonly BoardRoleRepository $roles,
        private readonly BoardAssignmentRepository $assignments,
    ) {
    }

    /**
     * @return list<BoardSeat>
     */
    public function seats(AssociationDate $today): array
    {
        $people = [];

        foreach ($this->people->all() as $person) {
            if ($person->id() !== null) {
                $people[$person->id()] = $person;
            }
        }

        $roles = [];

        foreach ($this->roles() as $role) {
            if ($role->id() !== null) {
                $roles[$role->id()] = $role;
            }
        }

        $seats = [];

        foreach ($this->assignments->all() as $assignment) {
            $person = $people[$assignment->personId()] ?? null;
            $role = $roles[$assignment->roleId()] ?? null;

            if ($person === null || $role === null || $assignment->id() === null || $role->id() === null) {
                continue;
            }

            if ($assignment->startedOn()->isAfter($today)) {
                $state = 'upcoming';
            } elseif ($person->status() !== PersonStatus::Deceased && $assignment->covers($today)) {
                $state = 'current';
            } else {
                $state = 'history';
            }

            $seats[] = new BoardSeat(
                $assignment->id(),
                (int) $person->id(),
                trim($person->firstName() . ' ' . $person->lastName()),
                $role->id(),
                $role->slug(),
                $role->name(),
                $role->sortOrder(),
                $role->allowsMultiple(),
                $assignment->startedOn()->iso(),
                $assignment->endedOn()?->iso(),
                $assignment->termLabel(),
                $assignment->publicContact(),
                $state
            );
        }

        usort($seats, static function (BoardSeat $left, BoardSeat $right): int {
            $byOrder = $left->sortOrder() <=> $right->sortOrder();

            if ($byOrder !== 0) {
                return $byOrder;
            }

            $byRole = strcasecmp($left->roleName(), $right->roleName());

            if ($byRole !== 0) {
                return $byRole;
            }

            return strcasecmp($left->personName(), $right->personName());
        });

        return $seats;
    }

    /**
     * @return list<BoardRole>
     */
    public function roles(): array
    {
        $roles = $this->roles->all();
        usort($roles, static function (BoardRole $left, BoardRole $right): int {
            $byOrder = $left->sortOrder() <=> $right->sortOrder();

            return $byOrder !== 0 ? $byOrder : (($left->id() ?? 0) <=> ($right->id() ?? 0));
        });

        return $roles;
    }

    /**
     * Non-deceased people, with membership context for the given date.
     * The context is a hint. BoardService remains the authority on placement.
     *
     * @return list<array{person_id: int, name: string, coverage: string, coverage_on: ?string}>
     */
    public function people(AssociationDate $today): array
    {
        $participants = $this->memberships->allParticipants();
        $periods = $this->memberships->all();
        $choices = [];

        foreach ($this->people->all() as $person) {
            if ($person->id() === null || $person->status() === PersonStatus::Deceased) {
                continue;
            }

            $personId = $person->id();
            $span = MemberCoverage::continuousCoverageFrom($personId, $today, $participants, $periods);
            $coverage = 'none';
            $on = null;

            if ($span !== null && $span->isOpenEnded()) {
                $coverage = 'active';
            } elseif ($span !== null && $span->endsOn() instanceof AssociationDate) {
                $coverage = 'ends';
                $on = $span->endsOn()->iso();
            } else {
                foreach (MemberCoverage::effectiveMemberCoverages($personId, $participants, $periods) as $row) {
                    if (! $row->startedOn()->isAfter($today)) {
                        continue;
                    }

                    if ($on === null || $row->startedOn()->iso() < $on) {
                        $coverage = 'starts';
                        $on = $row->startedOn()->iso();
                    }
                }
            }

            $choices[] = [
                'person_id' => $personId,
                'name' => trim($person->firstName() . ' ' . $person->lastName()),
                'coverage' => $coverage,
                'coverage_on' => $on,
            ];
        }

        usort($choices, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $choices;
    }
}
