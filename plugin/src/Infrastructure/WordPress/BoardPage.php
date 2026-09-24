<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

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
                self::text('term_label')
            );
            self::redirect($result);
        } catch (NotAllowed $error) {
            throw $error;
        } catch (BoardRuleException | \InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::failure($error));
        }
    }

    public static function end(): void
    {
        self::guard('assoc_end_assignment');

        if (self::text('confirm') !== '1') {
            self::redirect('confirm');
        }

        try {
            WordpressBoard::service()->end(
                self::integer('assignment_id'),
                AssociationDate::fromIso(self::text('ended_on'))
            );
            self::redirect('ended');
        } catch (NotAllowed $error) {
            throw $error;
        } catch (BoardRuleException | \InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::failure($error));
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
            self::notice()
        );
    }

    private static function guard(string $nonce): void
    {
        if (! current_user_can(Capabilities::MANAGE_BOARD)) {
            wp_die(esc_html__('You do not have permission to change the board.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    private static function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => 'foreningsplugin-board',
            'assoc_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    private static function failure(\Throwable $error): string
    {
        $known = [
            'Person was not found.',
            'Role was not found.',
            'Assignment was not found.',
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
        $messages = [
            'saved' => __('The assignment is saved.', 'foreningsplugin'),
            'replaced' => __('The assignment is saved. The previous holder ended the day before, and that row remains.', 'foreningsplugin'),
            'ended' => __('The assignment is ended. The row remains.', 'foreningsplugin'),
            'uncovered' => __('The selected person\'s membership does not cover the full assignment period.', 'foreningsplugin'),
            'overlap' => __('The role already has a holder on those dates.', 'foreningsplugin'),
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

        $class = in_array($notice, ['saved', 'replaced', 'ended'], true) ? 'notice-success' : 'notice-error';

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
