<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Board\BoardSeat;
use Foreningssystem\Application\Board\BoardWizard;
use Foreningssystem\Application\Board\BoardWizardMode;
use Foreningssystem\Application\Board\BoardWizardStep;
use Foreningssystem\Application\Board\BoardWizardTask;
use Foreningssystem\Application\Settings\BuiltinStructure;
use Foreningssystem\Domain\Board\BoardRole;

final class BoardScreen
{
    /**
     * @param list<BoardSeat> $seats
     * @param list<BoardRole> $roles
     * @param list<array{person_id: int, name: string, coverage: string, coverage_on: ?string}> $people
     * @param array{
     *     view: string,
     *     step: string,
     *     task: ?string,
     *     role_id: ?int,
     *     assignment_id: ?int,
     *     person_id: ?int,
     *     started_on: string,
     *     ended_on: string,
     *     public_contact: string,
     *     term_label: string
     * } $context
     */
    public static function render(
        array $seats,
        array $roles,
        array $people,
        bool $canEdit,
        string $notice,
        array $context = [],
    ): void {
        $wizard = new BoardWizard();
        $view = ($context['view'] ?? '') === 'history' ? 'history' : 'wizard';
        $step = BoardWizardStep::normalize($context['step'] ?? null);
        $task = BoardWizardTask::normalize($context['task'] ?? null);
        $roleId = isset($context['role_id']) && is_int($context['role_id']) ? $context['role_id'] : null;
        $assignmentId = isset($context['assignment_id']) && is_int($context['assignment_id']) ? $context['assignment_id'] : null;
        $personId = isset($context['person_id']) && is_int($context['person_id']) ? $context['person_id'] : null;
        $startedOn = is_string($context['started_on'] ?? null) ? (string) $context['started_on'] : '';
        $endedOn = is_string($context['ended_on'] ?? null) ? (string) $context['ended_on'] : '';
        $publicContact = is_string($context['public_contact'] ?? null) ? (string) $context['public_contact'] : '';
        $termLabel = is_string($context['term_label'] ?? null) ? (string) $context['term_label'] : '';

        if ($view !== 'history') {
            $step = $wizard->resolveStep(
                $step,
                $task,
                $roleId,
                $assignmentId,
                $seats,
                $roles,
                $personId,
                $startedOn,
                $endedOn
            );
        }

        $mode = $wizard->mode($seats);
        $current = self::where($seats, 'current');
        $upcoming = self::where($seats, 'upcoming');
        $history = self::where($seats, 'history');

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Board', 'foreningsplugin') . '</h1>';
        echo '<p>' . esc_html__('An assignment requires a membership that covers the whole period. The auditor and the election committee follow the same rule. A closed row is not deleted.', 'foreningsplugin') . '</p>';

        if ($notice !== '') {
            echo $notice;
        }

        if ($view === 'history') {
            self::historyView($history, $roles);
            echo '</div>';

            return;
        }

        match ($step) {
            BoardWizardStep::TASK => self::taskStep($mode, $wizard->availableTasks($seats, $roles)),
            BoardWizardStep::ROLE => self::roleStep($task ?? BoardWizardTask::ADD, $wizard, $seats, $roles),
            BoardWizardStep::PERSON => self::personStep(
                $task ?? BoardWizardTask::ADD,
                $wizard,
                $seats,
                $roles,
                $people,
                $roleId,
                $assignmentId,
                $personId,
                $startedOn,
                $endedOn,
                $publicContact,
                $termLabel
            ),
            BoardWizardStep::CONFIRM => self::confirmStep(
                $task ?? BoardWizardTask::ADD,
                $wizard,
                $seats,
                $roles,
                $people,
                $roleId,
                $assignmentId,
                $personId,
                $startedOn,
                $endedOn,
                $publicContact,
                $termLabel
            ),
            BoardWizardStep::DONE => self::doneStep(),
            default => self::overview($mode, $current, $upcoming, $roles, $wizard, $canEdit),
        };

        echo '</div>';
    }

    public static function roleLabel(string $slug, string $stored): string
    {
        $source = BuiltinStructure::boardSource($slug);

        return $source === null ? $stored : __($source, 'foreningsplugin');
    }

    /**
     * @param list<BoardSeat> $current
     * @param list<BoardSeat> $upcoming
     * @param list<BoardRole> $roles
     */
    private static function overview(
        string $mode,
        array $current,
        array $upcoming,
        array $roles,
        BoardWizard $wizard,
        bool $canEdit,
    ): void {
        echo '<div id="assoc-board-overview">';
        echo '<div id="assoc-board-current">';
        echo '<h2>' . esc_html__('Current board', 'foreningsplugin') . '</h2>';

        if ($current === []) {
            echo '<p>' . esc_html__('No current board assignments.', 'foreningsplugin') . '</p>';

            if ($mode === BoardWizardMode::GET_STARTED) {
                echo '<p>' . esc_html__('Add the first board assignment.', 'foreningsplugin') . '</p>';
            }
        } else {
            self::table($current, false, 'current');
        }

        echo '</div>';

        if ($upcoming !== []) {
            echo '<div id="assoc-board-upcoming">';
            echo '<h2>' . esc_html__('Upcoming changes', 'foreningsplugin') . '</h2>';
            self::table($upcoming, false, 'upcoming');
            echo '</div>';
        }

        $vacant = $mode === BoardWizardMode::CHANGE
            ? $wizard->vacantSingleRoles(array_merge($current, $upcoming), $roles)
            : [];

        if ($vacant !== [] || $upcoming !== []) {
            echo '<div id="assoc-board-warnings">';
            echo '<h2>' . esc_html__('Coverage', 'foreningsplugin') . '</h2>';

            foreach ($vacant as $role) {
                echo '<p>' . esc_html(sprintf(
                    /* translators: %s is the board role name. */
                    __('No current holder for %s.', 'foreningsplugin'),
                    self::roleLabel($role->slug(), $role->name())
                )) . '</p>';
            }

            foreach ($upcoming as $seat) {
                echo '<p>' . esc_html(sprintf(
                    /* translators: 1: role name, 2: person name, 3: start date. */
                    __('Scheduled: %1$s — %2$s starts %3$s.', 'foreningsplugin'),
                    self::roleLabel($seat->roleSlug(), $seat->roleName()),
                    $seat->personName(),
                    $seat->startedOn()
                )) . '</p>';
            }

            echo '</div>';
        }

        echo '<p><a id="assoc-board-history-link" href="' . esc_url(self::url(['assoc_view' => 'history'])) . '">'
            . esc_html__('Show history', 'foreningsplugin') . '</a></p>';

        if ($canEdit) {
            $cta = $mode === BoardWizardMode::GET_STARTED
                ? __('Get started', 'foreningsplugin')
                : __('Change the board', 'foreningsplugin');
            echo '<p><a class="button button-primary" href="' . esc_url(self::url(['assoc_board_step' => BoardWizardStep::TASK])) . '">'
                . esc_html($cta) . '</a></p>';
            echo '<p class="description">' . esc_html__('Board roles are defined in Association Settings. This guide only creates and changes assignments.', 'foreningsplugin') . '</p>';
        }

        echo '</div>';
    }

    /**
     * @param list<BoardSeat> $history
     * @param list<BoardRole> $roles
     */
    private static function historyView(array $history, array $roles): void
    {
        echo '<div id="assoc-board-history">';
        echo '<h2>' . esc_html__('History', 'foreningsplugin') . '</h2>';
        echo '<p><a href="' . esc_url(self::url()) . '">' . esc_html__('Back to board overview', 'foreningsplugin') . '</a></p>';

        if ($history === []) {
            echo '<p>' . esc_html__('No earlier board assignments.', 'foreningsplugin') . '</p>';
        } else {
            foreach ($roles as $role) {
                $rows = [];

                foreach ($history as $seat) {
                    if ($role->id() !== null && $seat->roleId() === $role->id()) {
                        $rows[] = $seat;
                    }
                }

                if ($rows === []) {
                    continue;
                }

                usort($rows, static fn (BoardSeat $left, BoardSeat $right): int => strcmp($right->startedOn(), $left->startedOn()));
                echo '<h3>' . esc_html(self::roleLabel($role->slug(), $role->name())) . '</h3>';
                self::table($rows, false, 'history');
            }
        }

        echo '</div>';
    }

    /**
     * @param list<string> $tasks
     */
    private static function taskStep(string $mode, array $tasks): void
    {
        echo '<div id="assoc-board-wizard-task">';
        echo '<h2>' . esc_html(
            $mode === BoardWizardMode::GET_STARTED
                ? __('Get started', 'foreningsplugin')
                : __('Change the board', 'foreningsplugin')
        ) . '</h2>';
        echo '<p>' . esc_html__('Choose one change at a time.', 'foreningsplugin') . '</p>';

        if ($tasks === []) {
            echo '<p>' . esc_html__('No board changes are available right now. Add members with covering membership, or define roles in Association Settings.', 'foreningsplugin') . '</p>';
        } else {
            echo '<ul class="assoc-board-task-list">';

            foreach ($tasks as $task) {
                $label = match ($task) {
                    BoardWizardTask::REPLACE => __('Replace role', 'foreningsplugin'),
                    BoardWizardTask::ADD => __('Add holder', 'foreningsplugin'),
                    BoardWizardTask::END => __('End assignment', 'foreningsplugin'),
                    BoardWizardTask::CANCEL => __('Cancel scheduled change', 'foreningsplugin'),
                    default => $task,
                };
                echo '<li><a class="button" href="' . esc_url(self::url([
                    'assoc_board_step' => BoardWizardStep::ROLE,
                    'assoc_board_task' => $task,
                ])) . '">' . esc_html($label) . '</a></li>';
            }

            echo '</ul>';
        }

        self::backLink(BoardWizardStep::OVERVIEW);
        echo '</div>';
    }

    /**
     * @param list<BoardSeat> $seats
     * @param list<BoardRole> $roles
     */
    private static function roleStep(string $task, BoardWizard $wizard, array $seats, array $roles): void
    {
        echo '<div id="assoc-board-wizard-role">';
        echo '<h2>' . esc_html__('Choose role', 'foreningsplugin') . '</h2>';
        $choices = $wizard->rolesForTask($task, $seats, $roles);

        if ($choices === []) {
            echo '<p>' . esc_html__('No roles are available for this change.', 'foreningsplugin') . '</p>';
        } else {
            echo '<ul class="assoc-board-role-list">';

            foreach ($choices as $role) {
                if ($role->id() === null) {
                    continue;
                }

                $label = self::roleLabel($role->slug(), $role->name());
                $hint = $role->allowsMultiple()
                    ? __('Several holders allowed', 'foreningsplugin')
                    : __('One holder', 'foreningsplugin');
                echo '<li><a class="button" href="' . esc_url(self::url([
                    'assoc_board_step' => BoardWizardStep::PERSON,
                    'assoc_board_task' => $task,
                    'assoc_role' => (string) $role->id(),
                ])) . '">' . esc_html($label) . '</a> <span class="description">' . esc_html($hint) . '</span></li>';
            }

            echo '</ul>';
        }

        self::backLink(BoardWizardStep::TASK, $task);
        echo '</div>';
    }

    /**
     * @param list<BoardSeat> $seats
     * @param list<BoardRole> $roles
     * @param list<array{person_id: int, name: string, coverage: string, coverage_on: ?string}> $people
     */
    private static function personStep(
        string $task,
        BoardWizard $wizard,
        array $seats,
        array $roles,
        array $people,
        ?int $roleId,
        ?int $assignmentId,
        ?int $personId,
        string $startedOn,
        string $endedOn,
        string $publicContact,
        string $termLabel,
    ): void {
        $role = $roleId === null ? null : $wizard->findRole($roles, $roleId);
        echo '<div id="assoc-board-wizard-person">';
        echo '<h2>' . esc_html__('Person and dates', 'foreningsplugin') . '</h2>';

        if ($role === null || $role->id() === null) {
            echo '<p>' . esc_html__('Choose a role first.', 'foreningsplugin') . '</p>';
            self::backLink(BoardWizardStep::ROLE, $task);
            echo '</div>';

            return;
        }

        echo '<p><strong>' . esc_html(self::roleLabel($role->slug(), $role->name())) . '</strong></p>';

        if (BoardWizardTask::needsPersonDates($task)) {
            self::placeContinueForm($task, $role->id(), $people, $personId, $startedOn, $endedOn, $publicContact, $termLabel, $task === BoardWizardTask::REPLACE);
        } else {
            self::assignmentContinueForm($task, $wizard->assignmentsForTask($task, $seats, $roleId), $assignmentId, $endedOn);
        }

        self::backLink(BoardWizardStep::ROLE, $task);
        echo '</div>';
    }

    /**
     * @param list<BoardSeat> $seats
     * @param list<BoardRole> $roles
     * @param list<array{person_id: int, name: string, coverage: string, coverage_on: ?string}> $people
     */
    private static function confirmStep(
        string $task,
        BoardWizard $wizard,
        array $seats,
        array $roles,
        array $people,
        ?int $roleId,
        ?int $assignmentId,
        ?int $personId,
        string $startedOn,
        string $endedOn,
        string $publicContact,
        string $termLabel,
    ): void {
        $role = $roleId === null ? null : $wizard->findRole($roles, $roleId);
        echo '<div id="assoc-board-wizard-confirm">';
        echo '<h2>' . esc_html__('Confirm', 'foreningsplugin') . '</h2>';

        if ($role === null || $role->id() === null) {
            echo '<p>' . esc_html__('Choose a role first.', 'foreningsplugin') . '</p>';
            self::backLink(BoardWizardStep::ROLE, $task);
            echo '</div>';

            return;
        }

        $roleName = self::roleLabel($role->slug(), $role->name());

        if (BoardWizardTask::needsPersonDates($task)) {
            $personName = self::personName($people, $personId);

            if ($personId === null || $personName === '' || $startedOn === '') {
                echo '<p>' . esc_html__('Person and start date are required.', 'foreningsplugin') . '</p>';
                self::backLink(BoardWizardStep::PERSON, $task, $roleId);
                echo '</div>';

                return;
            }

            $holders = $wizard->currentHoldersForRole($seats, $role->id());
            $holderNames = array_map(static fn (BoardSeat $seat): string => $seat->personName(), $holders);
            $holderEnd = $holders[0]->endedOn() ?? null;

            if ($task === BoardWizardTask::REPLACE && $holders !== []) {
                echo '<p class="description assoc-replace-preview" data-current="' . esc_attr(implode(', ', $holderNames)) . '" data-end="' . esc_attr((string) $holderEnd) . '">';
                echo esc_html(sprintf(
                    /* translators: 1: current holder, 2: new person, 3: start date. */
                    __('Confirm: %1$s\'s current assignment closes the day before %3$s if that date falls inside it. %2$s starts on %3$s. History is preserved.', 'foreningsplugin'),
                    implode(', ', $holderNames),
                    $personName,
                    $startedOn
                ));
                echo '</p>';
            } else {
                echo '<p>' . esc_html(sprintf(
                    /* translators: 1: person name, 2: role name, 3: start date. */
                    __('Confirm: add %1$s as %2$s starting %3$s. Existing holders of multi-holder roles stay in place. History is preserved.', 'foreningsplugin'),
                    $personName,
                    $roleName,
                    $startedOn
                )) . '</p>';
            }

            echo '<form method="post" class="' . esc_attr($task === BoardWizardTask::REPLACE ? 'assoc-replace-form' : 'assoc-place-form') . '" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_place_assignment">';
            echo '<input type="hidden" name="wizard" value="1">';
            echo '<input type="hidden" name="assoc_board_task" value="' . esc_attr($task) . '">';
            echo '<input type="hidden" name="person_id" value="' . esc_attr((string) $personId) . '">';
            echo '<input type="hidden" name="role_id" value="' . esc_attr((string) $role->id()) . '">';
            echo '<input type="hidden" name="started_on" value="' . esc_attr($startedOn) . '">';
            echo '<input type="hidden" name="ended_on" value="' . esc_attr($endedOn) . '">';
            echo '<input type="hidden" name="public_contact" value="' . esc_attr($publicContact) . '">';
            echo '<input type="hidden" name="term_label" value="' . esc_attr($termLabel) . '">';

            if ($task === BoardWizardTask::REPLACE && $holders !== []) {
                echo '<input type="hidden" class="assoc-current-holder" value="' . esc_attr(implode(', ', $holderNames)) . '">';
            }

            wp_nonce_field('assoc_place_assignment');
            echo '<p><label><input type="checkbox" name="confirm" value="1" required> '
                . esc_html__('I understand the current row may close and history is preserved.', 'foreningsplugin')
                . '</label></p>';
            submit_button(
                $task === BoardWizardTask::REPLACE
                    ? sprintf(
                        /* translators: %s is the board role name. */
                        __('Replace %s', 'foreningsplugin'),
                        $roleName
                    )
                    : __('Add holder', 'foreningsplugin'),
                'primary',
                'submit-confirm'
            );
            echo '</form>';
            self::script();
        } else {
            $candidates = $wizard->assignmentsForTask($task, $seats, $roleId);
            $seat = $assignmentId === null ? null : $wizard->findAssignment($candidates, $assignmentId);

            if ($seat === null) {
                echo '<p>' . esc_html__('Choose an assignment first.', 'foreningsplugin') . '</p>';
                self::backLink(BoardWizardStep::PERSON, $task, $roleId);
                echo '</div>';

                return;
            }

            if ($task === BoardWizardTask::END) {
                if ($endedOn === '') {
                    echo '<p>' . esc_html__('An end date is required.', 'foreningsplugin') . '</p>';
                    self::backLink(BoardWizardStep::PERSON, $task, $roleId, $assignmentId);
                    echo '</div>';

                    return;
                }

                echo '<p class="description assoc-end-preview" data-person="' . esc_attr($seat->personName()) . '" data-role="' . esc_attr($roleName) . '">';
                echo esc_html(sprintf(
                    /* translators: 1: person name, 2: role name, 3: end date. */
                    __('Confirm: end %1$s\'s %2$s assignment on %3$s. The historical assignment will remain.', 'foreningsplugin'),
                    $seat->personName(),
                    $roleName,
                    $endedOn
                ));
                echo '</p>';
                echo '<form method="post" class="assoc-end-form" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="assoc_end_assignment">';
                echo '<input type="hidden" name="wizard" value="1">';
                echo '<input type="hidden" name="assoc_board_task" value="' . esc_attr(BoardWizardTask::END) . '">';
                echo '<input type="hidden" name="role_id" value="' . esc_attr((string) $role->id()) . '">';
                echo '<input type="hidden" name="assignment_id" value="' . esc_attr((string) $seat->assignmentId()) . '">';
                echo '<input type="hidden" name="ended_on" value="' . esc_attr($endedOn) . '">';
                echo '<input type="hidden" name="confirm" value="1">';
                wp_nonce_field('assoc_end_assignment');
                submit_button(__('End assignment', 'foreningsplugin'), 'primary', 'end-' . $seat->assignmentId());
                echo '</form>';
            } else {
                echo '<p>' . esc_html(sprintf(
                    /* translators: 1: person name, 2: role name, 3: start date. */
                    __('Confirm: cancel %1$s\'s scheduled %2$s assignment starting %3$s? This assignment has not started and will not be kept as board history.', 'foreningsplugin'),
                    $seat->personName(),
                    $roleName,
                    $seat->startedOn()
                )) . '</p>';
                echo '<form method="post" class="assoc-cancel-form" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="assoc_cancel_assignment">';
                echo '<input type="hidden" name="wizard" value="1">';
                echo '<input type="hidden" name="assoc_board_task" value="' . esc_attr(BoardWizardTask::CANCEL) . '">';
                echo '<input type="hidden" name="role_id" value="' . esc_attr((string) $role->id()) . '">';
                echo '<input type="hidden" name="assignment_id" value="' . esc_attr((string) $seat->assignmentId()) . '">';
                echo '<input type="hidden" name="confirm" value="1">';
                wp_nonce_field('assoc_cancel_assignment');
                submit_button(__('Cancel scheduled assignment', 'foreningsplugin'), 'primary', 'cancel-' . $seat->assignmentId());
                echo '</form>';
            }
        }

        self::backLink(BoardWizardStep::PERSON, $task, $roleId, $assignmentId, [
            'assoc_person' => $personId === null ? '' : (string) $personId,
            'assoc_start' => $startedOn,
            'assoc_end' => $endedOn,
            'assoc_contact' => $publicContact,
            'assoc_term' => $termLabel,
        ]);
        echo '</div>';
    }

    private static function doneStep(): void
    {
        echo '<div id="assoc-board-wizard-done">';
        echo '<h2>' . esc_html__('Done', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('The board change is saved.', 'foreningsplugin') . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(self::url()) . '">'
            . esc_html__('Back to board overview', 'foreningsplugin') . '</a></p>';
        echo '</div>';
    }

    /**
     * @param list<array{person_id: int, name: string, coverage: string, coverage_on: ?string}> $people
     */
    private static function placeContinueForm(
        string $task,
        int $roleId,
        array $people,
        ?int $personId,
        string $startedOn,
        string $endedOn,
        string $publicContact,
        string $termLabel,
        bool $replace,
    ): void {
        // GET navigation only — mutation happens on the confirm step. No nested forms.
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="foreningsplugin-board">';
        echo '<input type="hidden" name="assoc_board_step" value="' . esc_attr(BoardWizardStep::CONFIRM) . '">';
        echo '<input type="hidden" name="assoc_board_task" value="' . esc_attr($task) . '">';
        echo '<input type="hidden" name="assoc_role" value="' . esc_attr((string) $roleId) . '">';
        self::personSelect($people, $personId);
        self::input('assoc-wizard-start', 'assoc_start', __('Start date', 'foreningsplugin'), 'date', true, $startedOn);

        if (! $replace) {
            self::input('assoc-wizard-end', 'assoc_end', __('End date', 'foreningsplugin'), 'date', false, $endedOn);
            echo '<p class="description">' . esc_html__('Leave the end date empty if the assignment continues until it is changed.', 'foreningsplugin') . '</p>';
        }

        self::input('assoc-wizard-contact', 'assoc_contact', __('Public contact', 'foreningsplugin'), 'text', false, $publicContact);
        echo '<p class="description">' . esc_html__('This may be shown on the public website for this board role.', 'foreningsplugin') . '</p>';
        self::input('assoc-wizard-term', 'assoc_term', __('Term', 'foreningsplugin'), 'text', false, $termLabel);
        submit_button(__('Continue to confirm', 'foreningsplugin'), 'primary', 'submit-person');
        echo '</form>';
    }

    /**
     * @param list<BoardSeat> $assignments
     */
    private static function assignmentContinueForm(string $task, array $assignments, ?int $assignmentId, string $endedOn): void
    {
        if ($assignments === []) {
            echo '<p>' . esc_html__('No assignments match this change.', 'foreningsplugin') . '</p>';

            return;
        }

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="foreningsplugin-board">';
        echo '<input type="hidden" name="assoc_board_step" value="' . esc_attr(BoardWizardStep::CONFIRM) . '">';
        echo '<input type="hidden" name="assoc_board_task" value="' . esc_attr($task) . '">';
        echo '<input type="hidden" name="assoc_role" value="' . esc_attr((string) $assignments[0]->roleId()) . '">';
        echo '<p><label for="assoc-wizard-assignment">' . esc_html__('Assignment', 'foreningsplugin') . '</label><br>';
        echo '<select id="assoc-wizard-assignment" name="assoc_assignment" required>';
        echo '<option value="">' . esc_html__('Choose assignment', 'foreningsplugin') . '</option>';

        foreach ($assignments as $seat) {
            $selected = $assignmentId === $seat->assignmentId() ? ' selected' : '';
            $label = $seat->personName() . ' — ' . self::dates($seat, $seat->state());
            echo '<option value="' . esc_attr((string) $seat->assignmentId()) . '"' . $selected . '>'
                . esc_html($label) . '</option>';
        }

        echo '</select></p>';

        if ($task === BoardWizardTask::END) {
            self::input('assoc-wizard-end-date', 'assoc_end', __('End date', 'foreningsplugin'), 'date', true, $endedOn);
        }

        submit_button(__('Continue to confirm', 'foreningsplugin'), 'primary', 'submit-person');
        echo '</form>';
    }

    /**
     * @param array<string, string> $extra
     */
    private static function backLink(
        string $step,
        ?string $task = null,
        ?int $roleId = null,
        ?int $assignmentId = null,
        array $extra = [],
    ): void {
        $args = ['assoc_board_step' => $step];

        if ($task !== null) {
            $args['assoc_board_task'] = $task;
        }

        if ($roleId !== null) {
            $args['assoc_role'] = (string) $roleId;
        }

        if ($assignmentId !== null) {
            $args['assoc_assignment'] = (string) $assignmentId;
        }

        foreach ($extra as $key => $value) {
            if ($value !== '') {
                $args[$key] = $value;
            }
        }

        echo '<p><a class="button" href="' . esc_url(self::url($args)) . '">' . esc_html__('Back', 'foreningsplugin') . '</a></p>';
    }

    /**
     * @param array<string, string> $args
     */
    private static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => 'foreningsplugin-board'], $args), admin_url('admin.php'));
    }

    /**
     * @param list<BoardSeat> $seats
     * @return list<BoardSeat>
     */
    private static function where(array $seats, string $state): array
    {
        return array_values(array_filter($seats, static fn (BoardSeat $seat): bool => $seat->state() === $state));
    }

    /**
     * @param list<BoardSeat> $seats
     */
    private static function table(array $seats, bool $canEdit, string $context): void
    {
        echo '<table class="widefat striped"><thead><tr>';

        foreach ([__('Role', 'foreningsplugin'), __('Person', 'foreningsplugin'), __('Dates', 'foreningsplugin'), __('Term', 'foreningsplugin'), __('Public contact', 'foreningsplugin')] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }

        if ($canEdit) {
            echo '<th scope="col">' . esc_html__('Actions', 'foreningsplugin') . '</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ($seats as $seat) {
            $role = self::roleLabel($seat->roleSlug(), $seat->roleName());
            echo '<tr>';
            echo '<td>' . esc_html($role) . '</td>';
            echo '<td><a href="' . esc_url(admin_url('admin.php?page=foreningsplugin-members&assoc_person=' . $seat->personId())) . '">' . esc_html($seat->personName()) . '</a></td>';
            echo '<td>' . esc_html(self::dates($seat, $context)) . '</td>';
            echo '<td>' . esc_html($seat->termLabel()) . '</td>';
            echo '<td>' . esc_html($seat->publicContact()) . '</td>';

            if ($canEdit) {
                echo '<td></td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function dates(BoardSeat $seat, string $context): string
    {
        unset($context);

        return match ($seat->datePresentation()) {
            'starts' => sprintf(
                /* translators: %s is the start date. */
                __('Starts %s', 'foreningsplugin'),
                $seat->startedOn()
            ),
            'since' => sprintf(
                /* translators: %s is a date. */
                __('Since %s', 'foreningsplugin'),
                $seat->startedOn()
            ),
            'present' => sprintf(
                /* translators: %s is the start date. */
                __('%s – present', 'foreningsplugin'),
                $seat->startedOn()
            ),
            default => $seat->startedOn() . ' – ' . $seat->endedOn(),
        };
    }

    /**
     * @param list<array{person_id: int, name: string, coverage: string, coverage_on: ?string}> $people
     */
    private static function personSelect(array $people, ?int $selectedId): void
    {
        echo '<p><label for="assoc-wizard-person">' . esc_html__('Person', 'foreningsplugin') . '<br>';
        echo '<select id="assoc-wizard-person" name="assoc_person" required>';
        echo '<option value="">' . esc_html__('Choose person', 'foreningsplugin') . '</option>';

        foreach ($people as $person) {
            $context = match ($person['coverage']) {
                'active' => __('active member', 'foreningsplugin'),
                'ends' => sprintf(
                    /* translators: %s is a date. */
                    __('membership ends %s', 'foreningsplugin'),
                    (string) $person['coverage_on']
                ),
                'starts' => sprintf(
                    /* translators: %s is a date. */
                    __('membership starts %s', 'foreningsplugin'),
                    (string) $person['coverage_on']
                ),
                default => __('not a current member', 'foreningsplugin'),
            };
            $selected = $selectedId === $person['person_id'] ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $person['person_id']) . '" data-name="' . esc_attr($person['name']) . '"' . $selected . '>';
            echo esc_html($person['name'] . ' — ' . $context);
            echo '</option>';
        }

        echo '</select></label></p>';
    }

    /**
     * @param list<array{person_id: int, name: string, coverage: string, coverage_on: ?string}> $people
     */
    private static function personName(array $people, ?int $personId): string
    {
        if ($personId === null) {
            return '';
        }

        foreach ($people as $person) {
            if ($person['person_id'] === $personId) {
                return $person['name'];
            }
        }

        return '';
    }

    private static function input(string $id, string $name, string $label, string $type, bool $required, string $value = ''): void
    {
        echo '<p><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><br>';
        echo '<input class="regular-text" id="' . esc_attr($id) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($required ? ' required' : '') . '></p>';
    }

    private static function script(): void
    {
        $replace = __('%1$s\'s assignment will end %2$s. %3$s\'s assignment will begin %4$s. The previous assignment remains in history.', 'foreningsplugin');
        $gap = __('The current assignment still ends %1$s. %2$s\'s assignment will begin %3$s. The earlier assignment remains in history.', 'foreningsplugin');
        echo '<script>';
        echo 'document.addEventListener("DOMContentLoaded",function(){';
        echo 'var replaceTemplate=' . wp_json_encode($replace) . ';';
        echo 'var gapTemplate=' . wp_json_encode($gap) . ';';
        echo 'function fill(template,values){return template.replace(/%(\\d+)\\$s/g,function(_,index){return values[Number(index)-1]||"";});}';
        echo 'function previousDay(iso){var parts=iso.split("-");if(parts.length!==3){return "";}var date=new Date(Date.UTC(Number(parts[0]),Number(parts[1])-1,Number(parts[2])));if(Number.isNaN(date.getTime())){return "";}date.setUTCDate(date.getUTCDate()-1);var month=String(date.getUTCMonth()+1).padStart(2,"0");var day=String(date.getUTCDate()).padStart(2,"0");return date.getUTCFullYear()+"-"+month+"-"+day;}';
        echo 'document.querySelectorAll(".assoc-replace-form").forEach(function(form){var preview=form.previousElementSibling;var holder=form.querySelector(".assoc-current-holder");var start=form.querySelector("[name=started_on]");if(!preview||!holder||!start||!start.value){return;}var end=previousDay(start.value);var name=preview.textContent;if(!end){return;}var planned=preview.getAttribute("data-end")||"";if(planned&&start.value>planned){preview.textContent=fill(gapTemplate,[planned,name,start.value]);}});';
        echo '});</script>';
    }
}
