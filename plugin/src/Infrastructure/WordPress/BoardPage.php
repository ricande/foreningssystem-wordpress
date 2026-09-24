<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardRuleException;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\PersonStatus;

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
        } catch (BoardRuleException $error) {
            self::redirect(self::noticeCode($error));
        } catch (\InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function end(): void
    {
        self::guard('assoc_end_assignment');

        try {
            WordpressBoard::service()->end(
                self::integer('assignment_id'),
                AssociationDate::fromIso(self::text('ended_on'))
            );
            self::redirect('ended');
        } catch (BoardRuleException $error) {
            self::redirect(self::noticeCode($error));
        } catch (\InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::VIEW_MEMBERS)) {
            wp_die(esc_html__('You do not have permission to view the board.', 'foreningsplugin'));
        }

        $today = AssociationDate::fromIso(wp_date('Y-m-d'));
        $board = WordpressBoard::service();
        $canEdit = current_user_can(Capabilities::MANAGE_BOARD);
        $posts = $board->history($today);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Board', 'foreningsplugin') . '</h1>';
        echo '<p>' . esc_html__('An assignment requires a membership that covers the whole period. The auditor and the election committee follow the same rule. A closed row is not deleted.', 'foreningsplugin') . '</p>';
        self::notice();

        if ($canEdit) {
            echo '<h2>' . esc_html__('New assignment', 'foreningsplugin') . '</h2>';
            echo '<p>' . esc_html__('If the role can have only one holder, the open assignment ends the day before the new start date.', 'foreningsplugin') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_place_assignment">';
            wp_nonce_field('assoc_place_assignment');
            echo '<p><label>' . esc_html__('Person', 'foreningsplugin') . ' <select name="person_id" required>';
            echo '<option value="">' . esc_html__('Choose person', 'foreningsplugin') . '</option>';

            foreach (WordpressPeople::service()->listPeople() as $record) {
                $person = $record->person();

                if ($person->status() === PersonStatus::Deceased || $person->id() === null) {
                    continue;
                }

                echo '<option value="' . esc_attr((string) $person->id()) . '">' . esc_html($person->firstName() . ' ' . $person->lastName()) . '</option>';
            }

            echo '</select></label></p>';
            echo '<p><label>' . esc_html__('Role', 'foreningsplugin') . ' <select name="role_id" required>';
            echo '<option value="">' . esc_html__('Choose role', 'foreningsplugin') . '</option>';

            foreach ($board->roles() as $role) {
                if ($role->id() === null) {
                    continue;
                }

                echo '<option value="' . esc_attr((string) $role->id()) . '">' . esc_html(self::roleLabel($role->slug(), $role->name())) . '</option>';
            }

            echo '</select></label></p>';
            self::field('started_on', __('Start date', 'foreningsplugin'), 'date', true);
            self::field('ended_on', __('End date', 'foreningsplugin'), 'date', false);
            self::field('public_contact', __('Public contact', 'foreningsplugin'), 'text', false);
            self::field('term_label', __('Term', 'foreningsplugin'), 'text', false);
            submit_button(__('Save assignment', 'foreningsplugin'));
            echo '</form>';
        }

        echo '<h2>' . esc_html__('Assignment', 'foreningsplugin') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';

        foreach ([__('Role', 'foreningsplugin'), __('Person', 'foreningsplugin'), __('Period', 'foreningsplugin'), __('Public contact', 'foreningsplugin'), __('Today', 'foreningsplugin')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }

        if ($canEdit) {
            echo '<th>' . esc_html__('Action', 'foreningsplugin') . '</th>';
        }

        echo '</tr></thead><tbody>';

        if ($posts === []) {
            echo '<tr><td colspan="6">' . esc_html__('No assignments yet.', 'foreningsplugin') . '</td></tr>';
        }

        foreach ($posts as $post) {
            $assignment = $post->assignment();
            $period = $assignment->startedOn()->iso();

            if ($assignment->endedOn() !== null) {
                $period .= ' – ' . $assignment->endedOn()->iso();
            }

            echo '<tr>';
            echo '<td>' . esc_html(self::roleLabel($post->roleSlug(), $post->roleName())) . '</td>';
            echo '<td>' . esc_html($post->personName()) . '</td>';
            echo '<td>' . esc_html($period) . '</td>';
            echo '<td>' . esc_html($assignment->publicContact()) . '</td>';
            echo '<td>' . esc_html($post->current() ? __('Yes', 'foreningsplugin') : __('No', 'foreningsplugin')) . '</td>';

            if ($canEdit) {
                echo '<td>';

                if ($assignment->endedOn() === null) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    echo '<input type="hidden" name="action" value="assoc_end_assignment">';
                    echo '<input type="hidden" name="assignment_id" value="' . esc_attr((string) $assignment->id()) . '">';
                    wp_nonce_field('assoc_end_assignment');
                    echo '<input type="date" name="ended_on" required> ';
                    submit_button(__('End assignment', 'foreningsplugin'), 'secondary', 'submit', false);
                    echo '</form>';
                }

                echo '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table></div>';
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

    private static function noticeCode(BoardRuleException $error): string
    {
        return match ($error->getMessage()) {
            'The membership does not cover this assignment.' => 'uncovered',
            'This role already has a holder for those dates.' => 'overlap',
            'The assignment is already ended.' => 'already_ended',
            'An assignment cannot end before it starts.' => 'before_start',
            'A deceased person cannot hold an open assignment.' => 'deceased',
            default => 'invalid',
        };
    }

    private static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';
        $messages = [
            'saved' => __('The assignment is saved.', 'foreningsplugin'),
            'replaced' => __('The assignment is saved. The previous holder ended the day before, and that row remains.', 'foreningsplugin'),
            'ended' => __('The assignment is ended. The row remains.', 'foreningsplugin'),
            'uncovered' => __('The membership does not cover the whole assignment.', 'foreningsplugin'),
            'overlap' => __('The role already has a holder on those dates.', 'foreningsplugin'),
            'already_ended' => __('The assignment is already ended.', 'foreningsplugin'),
            'before_start' => __('The assignment cannot end before the start date.', 'foreningsplugin'),
            'deceased' => __('A deceased person cannot have an open assignment.', 'foreningsplugin'),
            'invalid' => __('Check the details and try again.', 'foreningsplugin'),
        ];

        if (! isset($messages[$notice])) {
            return;
        }

        $class = in_array($notice, ['saved', 'replaced', 'ended'], true) ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }

    private static function roleLabel(string $slug, string $stored): string
    {
        return match ($slug) {
            'chair' => __('Chair', 'foreningsplugin'),
            'treasurer' => __('Treasurer', 'foreningsplugin'),
            'secretary' => __('Secretary', 'foreningsplugin'),
            'alternate' => __('Alternate', 'foreningsplugin'),
            'auditor' => __('Auditor', 'foreningsplugin'),
            'election_committee' => __('Election committee', 'foreningsplugin'),
            default => $stored,
        };
    }

    private static function field(string $name, string $label, string $type, bool $required): void
    {
        echo '<p><label>' . esc_html($label) . ' ';
        echo '<input class="regular-text" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '"' . ($required ? ' required' : '') . '>';
        echo '</label></p>';
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
