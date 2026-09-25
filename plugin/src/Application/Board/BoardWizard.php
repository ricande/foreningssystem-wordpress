<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Board;

use Foreningssystem\Domain\Board\BoardRole;

/**
 * Board admin wizard orchestration. Mutations remain in BoardService.
 */
final class BoardWizard
{
    /**
     * @param list<BoardSeat> $seats
     */
    public function mode(array $seats): string
    {
        return BoardWizardMode::fromSeats($seats);
    }

    /**
     * Tasks that currently have something to act on.
     *
     * @param list<BoardSeat> $seats
     * @param list<BoardRole> $roles
     * @return list<string>
     */
    public function availableTasks(array $seats, array $roles): array
    {
        $tasks = [];

        if ($this->rolesForTask(BoardWizardTask::ADD, $seats, $roles) !== []) {
            $tasks[] = BoardWizardTask::ADD;
        }

        if ($this->rolesForTask(BoardWizardTask::REPLACE, $seats, $roles) !== []) {
            $tasks[] = BoardWizardTask::REPLACE;
        }

        if ($this->rolesForTask(BoardWizardTask::END, $seats, $roles) !== []) {
            $tasks[] = BoardWizardTask::END;
        }

        if ($this->rolesForTask(BoardWizardTask::CANCEL, $seats, $roles) !== []) {
            $tasks[] = BoardWizardTask::CANCEL;
        }

        return $tasks;
    }

    /**
     * @param list<BoardSeat> $seats
     * @param list<BoardRole> $roles
     * @return list<BoardRole>
     */
    public function rolesForTask(string $task, array $seats, array $roles): array
    {
        $task = BoardWizardTask::normalize($task);

        if ($task === null) {
            return [];
        }

        $matched = [];

        foreach ($roles as $role) {
            if ($role->id() === null) {
                continue;
            }

            if ($this->roleOffersTask($task, $role, $seats)) {
                $matched[] = $role;
            }
        }

        return $matched;
    }

    /**
     * @param list<BoardSeat> $seats
     * @return list<BoardSeat>
     */
    public function assignmentsForTask(string $task, array $seats, ?int $roleId): array
    {
        $task = BoardWizardTask::normalize($task);

        if ($task === null) {
            return [];
        }

        $wanted = match ($task) {
            BoardWizardTask::END => 'current',
            BoardWizardTask::CANCEL => 'upcoming',
            default => null,
        };

        if ($wanted === null) {
            return [];
        }

        $rows = [];

        foreach ($seats as $seat) {
            if ($seat->state() !== $wanted) {
                continue;
            }

            if ($roleId !== null && $seat->roleId() !== $roleId) {
                continue;
            }

            if ($task === BoardWizardTask::END && $seat->endedOn() !== null) {
                continue;
            }

            $rows[] = $seat;
        }

        return $rows;
    }

    /**
     * @param list<BoardSeat> $seats
     * @return list<BoardSeat>
     */
    public function currentHoldersForRole(array $seats, int $roleId): array
    {
        return array_values(array_filter(
            $seats,
            static fn (BoardSeat $seat): bool => $seat->state() === 'current' && $seat->roleId() === $roleId
        ));
    }

    /**
     * @param list<BoardSeat> $seats
     * @return list<BoardSeat>
     */
    public function upcomingForRole(array $seats, int $roleId): array
    {
        return array_values(array_filter(
            $seats,
            static fn (BoardSeat $seat): bool => $seat->state() === 'upcoming' && $seat->roleId() === $roleId
        ));
    }

    /**
     * Single-holder roles with no current holder today (coverage warning).
     *
     * @param list<BoardSeat> $seats
     * @param list<BoardRole> $roles
     * @return list<BoardRole>
     */
    public function vacantSingleRoles(array $seats, array $roles): array
    {
        $vacant = [];

        foreach ($roles as $role) {
            if ($role->id() === null || $role->allowsMultiple()) {
                continue;
            }

            if ($this->currentHoldersForRole($seats, $role->id()) === []) {
                $vacant[] = $role;
            }
        }

        return $vacant;
    }

    /**
     * Resolve and clamp the requested step so missing selections fall back safely.
     *
     * @param list<BoardSeat> $seats
     * @param list<BoardRole> $roles
     */
    public function resolveStep(
        ?string $requested,
        ?string $task,
        ?int $roleId,
        ?int $assignmentId,
        array $seats,
        array $roles,
        ?int $personId = null,
        string $startedOn = '',
        string $endedOn = '',
    ): string {
        $step = BoardWizardStep::normalize($requested);
        $task = BoardWizardTask::normalize($task);

        if ($step === BoardWizardStep::OVERVIEW || $step === BoardWizardStep::DONE) {
            return $step;
        }

        if ($step === BoardWizardStep::TASK) {
            return BoardWizardStep::TASK;
        }

        if ($task === null) {
            return BoardWizardStep::TASK;
        }

        if (! in_array($task, $this->availableTasks($seats, $roles), true)) {
            return BoardWizardStep::TASK;
        }

        if ($step === BoardWizardStep::ROLE) {
            return BoardWizardStep::ROLE;
        }

        $roleOk = $roleId !== null && $this->roleOffersTask($task, $this->findRole($roles, $roleId), $seats);

        if (! $roleOk) {
            return BoardWizardStep::ROLE;
        }

        if ($step === BoardWizardStep::PERSON) {
            return BoardWizardStep::PERSON;
        }

        if (BoardWizardTask::needsAssignment($task)) {
            $assignmentOk = $assignmentId !== null && $this->findAssignment(
                $this->assignmentsForTask($task, $seats, $roleId),
                $assignmentId
            ) !== null;

            if (! $assignmentOk) {
                return BoardWizardStep::PERSON;
            }

            if ($task === BoardWizardTask::END && $endedOn === '') {
                return BoardWizardStep::PERSON;
            }
        }

        if (BoardWizardTask::needsPersonDates($task) && ($personId === null || $startedOn === '')) {
            return BoardWizardStep::PERSON;
        }

        if ($step === BoardWizardStep::CONFIRM) {
            return BoardWizardStep::CONFIRM;
        }

        return BoardWizardStep::PERSON;
    }

    /**
     * @param list<BoardSeat> $seats
     */
    public function findAssignment(array $seats, int $assignmentId): ?BoardSeat
    {
        foreach ($seats as $seat) {
            if ($seat->assignmentId() === $assignmentId) {
                return $seat;
            }
        }

        return null;
    }

    /**
     * @param list<BoardRole> $roles
     */
    public function findRole(array $roles, int $roleId): ?BoardRole
    {
        foreach ($roles as $role) {
            if ($role->id() === $roleId) {
                return $role;
            }
        }

        return null;
    }

    /**
     * @param list<BoardSeat> $seats
     */
    private function roleOffersTask(string $task, ?BoardRole $role, array $seats): bool
    {
        if ($role === null || $role->id() === null) {
            return false;
        }

        $roleId = $role->id();
        $current = $this->currentHoldersForRole($seats, $roleId);
        $upcoming = $this->upcomingForRole($seats, $roleId);

        return match ($task) {
            BoardWizardTask::REPLACE => ! $role->allowsMultiple()
                && $current !== []
                && $upcoming === [],
            BoardWizardTask::ADD => $role->allowsMultiple()
                || ($current === [] && $upcoming === []),
            BoardWizardTask::END => array_values(array_filter(
                $current,
                static fn (BoardSeat $seat): bool => $seat->endedOn() === null
            )) !== [],
            BoardWizardTask::CANCEL => $upcoming !== [],
            default => false,
        };
    }
}
