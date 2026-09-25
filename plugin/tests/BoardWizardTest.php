<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Board\BoardSeat;
use Foreningssystem\Application\Board\BoardWizard;
use Foreningssystem\Application\Board\BoardWizardMode;
use Foreningssystem\Application\Board\BoardWizardStep;
use Foreningssystem\Application\Board\BoardWizardTask;
use Foreningssystem\Domain\Board\BoardRole;
use PHPUnit\Framework\TestCase;

final class BoardWizardTest extends TestCase
{
    public function test_empty_board_is_get_started_mode(): void
    {
        $wizard = new BoardWizard();
        self::assertSame(BoardWizardMode::GET_STARTED, $wizard->mode([]));
        self::assertSame(
            BoardWizardMode::GET_STARTED,
            $wizard->mode([
                $this->seat(1, 1, 'Ada', 1, 'chair', 'Chair', false, '2024-01-01', '2024-12-31', 'history'),
            ])
        );
    }

    public function test_current_or_upcoming_is_change_mode(): void
    {
        $wizard = new BoardWizard();
        self::assertSame(
            BoardWizardMode::CHANGE,
            $wizard->mode([
                $this->seat(1, 1, 'Ada', 1, 'chair', 'Chair', false, '2024-01-01', null, 'current'),
            ])
        );
        self::assertSame(
            BoardWizardMode::CHANGE,
            $wizard->mode([
                $this->seat(2, 2, 'Grace', 1, 'chair', 'Chair', false, '2027-01-01', null, 'upcoming'),
            ])
        );
    }

    public function test_invalid_step_and_task_normalize(): void
    {
        self::assertSame(BoardWizardStep::OVERVIEW, BoardWizardStep::normalize('nope'));
        self::assertSame(BoardWizardStep::OVERVIEW, BoardWizardStep::normalize(null));
        self::assertNull(BoardWizardTask::normalize('mass-import'));
        self::assertSame(BoardWizardTask::REPLACE, BoardWizardTask::normalize('replace'));
    }

    public function test_available_tasks_depend_on_seats(): void
    {
        $wizard = new BoardWizard();
        $chair = new BoardRole(1, 'chair', 'Chair', false, 10);
        $member = new BoardRole(2, 'member', 'Board member', true, 40);

        self::assertSame(
            [BoardWizardTask::ADD],
            $wizard->availableTasks([], [$chair, $member])
        );

        $withChair = [
            $this->seat(10, 5, 'Ada', 1, 'chair', 'Chair', false, '2025-01-01', null, 'current'),
        ];
        self::assertSame(
            [BoardWizardTask::ADD, BoardWizardTask::REPLACE, BoardWizardTask::END],
            $wizard->availableTasks($withChair, [$chair, $member])
        );

        $scheduled = [
            $this->seat(10, 5, 'Ada', 1, 'chair', 'Chair', false, '2025-01-01', '2026-12-31', 'current'),
            $this->seat(11, 6, 'Grace', 1, 'chair', 'Chair', false, '2027-01-01', null, 'upcoming'),
        ];
        $tasks = $wizard->availableTasks($scheduled, [$chair, $member]);
        self::assertContains(BoardWizardTask::CANCEL, $tasks);
        self::assertNotContains(BoardWizardTask::REPLACE, $tasks);
        self::assertContains(BoardWizardTask::ADD, $tasks);
        self::assertNotContains(BoardWizardTask::END, $tasks);
    }

    public function test_replace_blocked_when_successor_scheduled(): void
    {
        $wizard = new BoardWizard();
        $chair = new BoardRole(1, 'chair', 'Chair', false, 10);
        $seats = [
            $this->seat(10, 5, 'Ada', 1, 'chair', 'Chair', false, '2025-01-01', '2026-12-31', 'current'),
            $this->seat(11, 6, 'Grace', 1, 'chair', 'Chair', false, '2027-01-01', null, 'upcoming'),
        ];
        self::assertSame([], $wizard->rolesForTask(BoardWizardTask::REPLACE, $seats, [$chair]));
        self::assertSame([$chair], $wizard->rolesForTask(BoardWizardTask::CANCEL, $seats, [$chair]));
    }

    public function test_resolve_step_falls_back_without_task_or_role(): void
    {
        $wizard = new BoardWizard();
        $chair = new BoardRole(1, 'chair', 'Chair', false, 10);
        $seats = [
            $this->seat(10, 5, 'Ada', 1, 'chair', 'Chair', false, '2025-01-01', null, 'current'),
        ];

        self::assertSame(
            BoardWizardStep::TASK,
            $wizard->resolveStep(BoardWizardStep::ROLE, null, null, null, $seats, [$chair])
        );
        self::assertSame(
            BoardWizardStep::ROLE,
            $wizard->resolveStep(BoardWizardStep::PERSON, BoardWizardTask::REPLACE, null, null, $seats, [$chair])
        );
        self::assertSame(
            BoardWizardStep::PERSON,
            $wizard->resolveStep(
                BoardWizardStep::CONFIRM,
                BoardWizardTask::REPLACE,
                1,
                null,
                $seats,
                [$chair]
            )
        );
        self::assertSame(
            BoardWizardStep::CONFIRM,
            $wizard->resolveStep(
                BoardWizardStep::CONFIRM,
                BoardWizardTask::REPLACE,
                1,
                null,
                $seats,
                [$chair],
                5,
                '2026-04-01'
            )
        );
        self::assertSame(
            BoardWizardStep::CONFIRM,
            $wizard->resolveStep(
                BoardWizardStep::CONFIRM,
                BoardWizardTask::END,
                1,
                10,
                $seats,
                [$chair],
                null,
                '',
                '2026-08-31'
            )
        );
    }

    public function test_vacant_single_roles(): void
    {
        $wizard = new BoardWizard();
        $chair = new BoardRole(1, 'chair', 'Chair', false, 10);
        $treasurer = new BoardRole(2, 'treasurer', 'Treasurer', false, 20);
        $member = new BoardRole(3, 'member', 'Board member', true, 40);
        $seats = [
            $this->seat(10, 5, 'Ada', 1, 'chair', 'Chair', false, '2025-01-01', null, 'current'),
        ];
        $vacant = $wizard->vacantSingleRoles($seats, [$chair, $treasurer, $member]);
        self::assertCount(1, $vacant);
        self::assertSame(2, $vacant[0]->id());
    }

    private function seat(
        int $assignmentId,
        int $personId,
        string $personName,
        int $roleId,
        string $slug,
        string $roleName,
        bool $multiple,
        string $startedOn,
        ?string $endedOn,
        string $state,
    ): BoardSeat {
        return new BoardSeat(
            $assignmentId,
            $personId,
            $personName,
            $roleId,
            $slug,
            $roleName,
            10,
            $multiple,
            $startedOn,
            $endedOn,
            '',
            '',
            $state
        );
    }
}
