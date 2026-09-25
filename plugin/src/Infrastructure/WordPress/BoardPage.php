<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Board\BoardWizardStep;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardRuleException;
use Foreningssystem\Domain\Membership\AssociationDate;

final class BoardPage
{
    public static function place(): void
    {
        self::guard('assoc_place_assignment');

        try {
            $ended = self::text('ended_on');
            $result = WordpressBoard::service()->place(
                self::integer('person_id'),
                self::integer('role_id'),
                AssociationDate::fromIso(self::text('started_on')),
                $ended === '' ? null : AssociationDate::fromIso($ended),
                self::text('public_contact'),
                self::text('term_label'),
                AssociationDate::fromIso(wp_date('Y-m-d'))
            );
            self::redirect($result, self::doneQuery());
        } catch (NotAllowed $error) {
            throw $error;
        } catch (BoardRuleException | \InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::failure($error), self::wizardQuery());
        }
    }

    public static function end(): void
    {
        self::guard('assoc_end_assignment');

        if (self::text('confirm') !== '1') {
            self::redirect('confirm', self::fromWizard() ? self::wizardQuery() : []);
        }

        try {
            WordpressBoard::service()->end(
                self::integer('assignment_id'),
                AssociationDate::fromIso(self::text('ended_on'))
            );
            self::redirect('ended', self::doneQuery());
        } catch (NotAllowed $error) {
            throw $error;
        } catch (BoardRuleException | \InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::failure($error), self::fromWizard() ? self::wizardQuery() : []);
        }
    }

    public static function cancelScheduled(): void
    {
        self::guard('assoc_cancel_assignment');

        if (self::text('confirm') !== '1') {
            self::redirect('cancel_confirm', self::fromWizard() ? self::wizardQuery() : []);
        }

        try {
            $result = WordpressBoard::service()->cancelScheduled(
                self::integer('assignment_id'),
                AssociationDate::fromIso(wp_date('Y-m-d'))
            );
            self::redirect('cancelled', array_merge(self::doneQuery(), [
                'assoc_role' => $result->roleSlug(),
                'assoc_role_name' => $result->roleName(),
                'assoc_end' => $result->currentEnd() ?? '',
            ]));
        } catch (NotAllowed $error) {
            throw $error;
        } catch (BoardRuleException | \InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::failure($error), self::fromWizard() ? self::wizardQuery() : []);
        }
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::VIEW_MEMBERS)) {
            wp_die(esc_html__('You do not have permission to view the board.', 'foreningsplugin'));
        }

        $today = AssociationDate::fromIso(wp_date('Y-m-d'));
        $directory = WordpressBoard::directory();
        BoardScreen::render(
            $directory->seats($today),
            $directory->roles(),
            $directory->people($today),
            current_user_can(Capabilities::MANAGE_BOARD),
            self::notice(),
            self::context()
        );
    }

    /**
     * @return array{
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
     * }
     */
    private static function context(): array
    {
        $role = isset($_GET['assoc_role']) ? (string) $_GET['assoc_role'] : '';
        $assignment = isset($_GET['assoc_assignment']) ? (string) $_GET['assoc_assignment'] : '';
        $person = isset($_GET['assoc_person']) ? (string) $_GET['assoc_person'] : '';

        return [
            'view' => isset($_GET['assoc_view']) ? sanitize_key((string) $_GET['assoc_view']) : '',
            'step' => isset($_GET['assoc_board_step']) ? sanitize_key((string) $_GET['assoc_board_step']) : BoardWizardStep::OVERVIEW,
            'task' => isset($_GET['assoc_board_task']) ? sanitize_key((string) $_GET['assoc_board_task']) : null,
            'role_id' => ctype_digit($role) ? (int) $role : null,
            'assignment_id' => ctype_digit($assignment) ? (int) $assignment : null,
            'person_id' => ctype_digit($person) ? (int) $person : null,
            'started_on' => isset($_GET['assoc_start']) ? sanitize_text_field(wp_unslash((string) $_GET['assoc_start'])) : '',
            'ended_on' => isset($_GET['assoc_end']) ? sanitize_text_field(wp_unslash((string) $_GET['assoc_end'])) : '',
            'public_contact' => isset($_GET['assoc_contact']) ? sanitize_text_field(wp_unslash((string) $_GET['assoc_contact'])) : '',
            'term_label' => isset($_GET['assoc_term']) ? sanitize_text_field(wp_unslash((string) $_GET['assoc_term'])) : '',
        ];
    }

    private static function fromWizard(): bool
    {
        return self::text('wizard') === '1';
    }

    /**
     * @return array<string, string>
     */
    private static function doneQuery(): array
    {
        return [
            'assoc_board_step' => BoardWizardStep::DONE,
        ];
    }

    /**
     * Preserve wizard draft fields on failure redirects when posted from confirm.
     *
     * @return array<string, string>
     */
    private static function wizardQuery(): array
    {
        if (! self::fromWizard()) {
            return [];
        }

        $query = [
            'assoc_board_step' => BoardWizardStep::CONFIRM,
        ];

        $task = self::text('assoc_board_task');

        if ($task === '' && self::integer('assignment_id') > 0) {
            $task = self::text('ended_on') !== '' ? 'end' : 'cancel';
        }

        if ($task === '' && self::integer('person_id') > 0) {
            $task = self::text('ended_on') !== '' ? 'add' : 'replace';
        }

        if ($task !== '') {
            $query['assoc_board_task'] = sanitize_key($task);
        }

        if (self::integer('role_id') > 0) {
            $query['assoc_role'] = (string) self::integer('role_id');
        }

        if (self::integer('assignment_id') > 0) {
            $query['assoc_assignment'] = (string) self::integer('assignment_id');
        }

        if (self::integer('person_id') > 0) {
            $query['assoc_person'] = (string) self::integer('person_id');
        }

        foreach (['started_on' => 'assoc_start', 'ended_on' => 'assoc_end', 'public_contact' => 'assoc_contact', 'term_label' => 'assoc_term'] as $post => $get) {
            $value = self::text($post);

            if ($value !== '') {
                $query[$get] = $value;
            }
        }

        return $query;
    }

    private static function guard(string $nonce): void
    {
        if (! current_user_can(Capabilities::MANAGE_BOARD)) {
            wp_die(esc_html__('You do not have permission to change the board.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    /**
     * @param array<string, string> $extra
     */
    private static function redirect(string $notice, array $extra = []): void
    {
        wp_safe_redirect(add_query_arg(array_merge([
            'page' => 'foreningsplugin-board',
            'assoc_notice' => $notice,
        ], $extra), admin_url('admin.php')));
        exit;
    }

    private static function failure(\Throwable $error): string
    {
        $known = [
            'Person was not found.',
            'Role was not found.',
            'Assignment was not found.',
            'The assignment could not be removed.',
        ];

        if (
            $error instanceof \RuntimeException
            && ! $error instanceof BoardRuleException
            && ! in_array($error->getMessage(), $known, true)
        ) {
            throw $error;
        }

        return self::noticeCode($error);
    }

    private static function noticeCode(\Throwable $error): string
    {
        return match ($error->getMessage()) {
            'The membership does not cover this assignment.' => 'uncovered',
            'This role already has a holder for those dates.' => 'overlap',
            'This role already has a scheduled assignment.' => 'scheduled',
            'Only a scheduled assignment that has not started can be cancelled.' => 'not_scheduled',
            'The assignment could not be removed.' => 'cancel_failed',
            'The assignment is already ended.' => 'already_ended',
            'An assignment cannot end before it starts.' => 'before_start',
            'A deceased person cannot hold an open assignment.' => 'deceased',
            'Person was not found.' => 'person',
            'Role was not found.' => 'role',
            'Assignment was not found.' => 'assignment',
            default => 'invalid',
        };
    }

    private static function notice(): string
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';
        $end = isset($_GET['assoc_end']) ? sanitize_text_field(wp_unslash((string) $_GET['assoc_end'])) : '';
        $role = isset($_GET['assoc_role']) ? sanitize_key((string) $_GET['assoc_role']) : '';
        $roleName = isset($_GET['assoc_role_name']) ? sanitize_text_field(wp_unslash((string) $_GET['assoc_role_name'])) : $role;
        $cancelled = __('The scheduled assignment was cancelled.', 'foreningsplugin');

        if ($notice === 'cancelled' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) === 1) {
            $cancelled = sprintf(
                /* translators: 1: role name, 2: date. */
                __('The scheduled assignment was cancelled. The current %1$s still ends on %2$s. Review the current assignment if that end date should change.', 'foreningsplugin'),
                BoardScreen::roleLabel($role, $roleName),
                $end
            );
        }

        $messages = [
            'saved' => __('The assignment is saved.', 'foreningsplugin'),
            'replaced' => __('The assignment is saved. The previous holder ended the day before, and that row remains.', 'foreningsplugin'),
            'ended' => __('The assignment is ended. The row remains.', 'foreningsplugin'),
            'cancelled' => $cancelled,
            'uncovered' => __('The selected person\'s membership does not cover the full assignment period.', 'foreningsplugin'),
            'overlap' => __('The role already has a holder on those dates.', 'foreningsplugin'),
            'scheduled' => __('This role already has a scheduled assignment. Cancel that assignment before adding another.', 'foreningsplugin'),
            'not_scheduled' => __('Only a scheduled assignment that has not started can be cancelled.', 'foreningsplugin'),
            'cancel_failed' => __('The scheduled assignment could not be cancelled.', 'foreningsplugin'),
            'cancel_confirm' => __('Confirm before cancelling the scheduled assignment.', 'foreningsplugin'),
            'already_ended' => __('The assignment is already ended.', 'foreningsplugin'),
            'before_start' => __('The assignment cannot end before the start date.', 'foreningsplugin'),
            'deceased' => __('A deceased person cannot have an open assignment.', 'foreningsplugin'),
            'person' => __('That person could not be found.', 'foreningsplugin'),
            'role' => __('That role could not be found.', 'foreningsplugin'),
            'assignment' => __('That assignment could not be found.', 'foreningsplugin'),
            'confirm' => __('Confirm the end date before ending the assignment.', 'foreningsplugin'),
            'invalid' => __('Check the details and try again.', 'foreningsplugin'),
        ];

        if (! isset($messages[$notice])) {
            return '';
        }

        $class = in_array($notice, ['saved', 'replaced', 'ended', 'cancelled'], true) ? 'notice-success' : 'notice-error';

        return '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }

    private static function text(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    }

    private static function integer(string $key): int
    {
        $value = $_POST[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
