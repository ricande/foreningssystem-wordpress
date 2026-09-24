<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MeetingType;

final class MeetingsPage
{
    public static function schedule(): void
    {
        self::guardManage('assoc_schedule_meeting');

        try {
            WordpressMeetings::service()->schedule(
                self::integer('type_id'),
                self::text('title'),
                MeetingMoment::fromLocal(self::text('meeting_date') . ' ' . self::text('meeting_time')),
                self::text('place')
            );
            self::redirect('scheduled');
        } catch (MeetingRuleException | \InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function start(): void
    {
        self::guardRecord('assoc_start_meeting');

        try {
            WordpressMeetings::service()->start(self::integer('meeting_id'));
            self::redirect('started');
        } catch (MeetingRuleException $error) {
            self::redirect(self::noticeCode($error));
        }
    }

    public static function markHeld(): void
    {
        self::guardRecord('assoc_mark_meeting_held');

        try {
            WordpressMeetings::service()->markHeld(self::integer('meeting_id'));
            self::redirect('held');
        } catch (MeetingRuleException $error) {
            self::redirect(self::noticeCode($error));
        }
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::VIEW_INTERNAL_MEETINGS)) {
            wp_die(esc_html__('Du har inte behörighet att se möten.', 'foreningsplugin'));
        }

        $meetingId = isset($_GET['meeting']) ? absint($_GET['meeting']) : 0;

        if ($meetingId > 0) {
            MeetingDetailPage::render($meetingId);

            return;
        }

        $service = WordpressMeetings::service();
        $canManage = current_user_can(Capabilities::MANAGE_MEETINGS);
        $canRecord = $canManage || current_user_can(Capabilities::RECORD_MEETING);
        $types = [];

        foreach ($service->types() as $type) {
            if ($type->id() !== null) {
                $types[$type->id()] = $type;
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Möten', 'foreningsplugin') . '</h1>';
        echo '<p>' . esc_html__('Ett hållet möte är inte ett protokoll. Anteckningar och protokoll kommer senare.', 'foreningsplugin') . '</p>';
        self::notice();

        if ($canManage) {
            echo '<h2>' . esc_html__('Nytt möte', 'foreningsplugin') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_schedule_meeting">';
            wp_nonce_field('assoc_schedule_meeting');
            echo '<p><label>' . esc_html__('Typ', 'foreningsplugin') . ' <select name="type_id" required>';
            echo '<option value="">' . esc_html__('Välj typ', 'foreningsplugin') . '</option>';

            foreach ($types as $type) {
                echo '<option value="' . esc_attr((string) $type->id()) . '">' . esc_html(self::typeLabel($type)) . '</option>';
            }

            echo '</select></label></p>';
            self::field('title', __('Titel', 'foreningsplugin'), 'text', true);
            self::field('meeting_date', __('Datum', 'foreningsplugin'), 'date', true);
            self::field('meeting_time', __('Tid', 'foreningsplugin'), 'time', true);
            self::field('place', __('Plats', 'foreningsplugin'), 'text', false);
            submit_button(__('Spara möte', 'foreningsplugin'));
            echo '</form>';
        }

        echo '<h2>' . esc_html__('Planerade och hållna möten', 'foreningsplugin') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';

        foreach ([__('Möte', 'foreningsplugin'), __('Typ', 'foreningsplugin'), __('Tid', 'foreningsplugin'), __('Plats', 'foreningsplugin'), __('Läge', 'foreningsplugin')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }

        if ($canRecord) {
            echo '<th>' . esc_html__('Åtgärd', 'foreningsplugin') . '</th>';
        }

        echo '</tr></thead><tbody>';
        $meetings = $service->listMeetings();

        if ($meetings === []) {
            echo '<tr><td colspan="6">' . esc_html__('Inga möten ännu.', 'foreningsplugin') . '</td></tr>';
        }

        foreach ($meetings as $meeting) {
            $type = $types[$meeting->typeId()] ?? null;
            echo '<tr>';
            $meetingUrl = add_query_arg([
                'page' => 'foreningsplugin-meetings',
                'meeting' => (int) $meeting->id(),
            ], admin_url('admin.php'));
            echo '<td><a href="' . esc_url($meetingUrl) . '">' . esc_html($meeting->title()) . '</a></td>';
            echo '<td>' . esc_html($type instanceof MeetingType ? self::typeLabel($type) : '') . '</td>';
            echo '<td>' . esc_html($meeting->startsAt()->date() . ' ' . $meeting->startsAt()->time()) . '</td>';
            echo '<td>' . esc_html($meeting->place()) . '</td>';
            echo '<td>' . esc_html(self::statusLabel($meeting->status())) . '</td>';

            if ($canRecord) {
                echo '<td>';

                if ($meeting->status() === MeetingStatus::Planned) {
                    self::transitionForm((int) $meeting->id(), 'assoc_start_meeting', __('Starta mötet', 'foreningsplugin'));
                }

                if ($meeting->status() === MeetingStatus::Planned || $meeting->status() === MeetingStatus::InProgress) {
                    self::transitionForm((int) $meeting->id(), 'assoc_mark_meeting_held', __('Markera som hållet', 'foreningsplugin'));
                }

                echo '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private static function transitionForm(int $meetingId, string $action, string $label): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:0.5em">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
        wp_nonce_field($action);
        submit_button($label, 'secondary', 'submit', false);
        echo '</form>';
    }

    private static function guardManage(string $nonce): void
    {
        if (! current_user_can(Capabilities::MANAGE_MEETINGS)) {
            wp_die(esc_html__('Du har inte behörighet att planera möten.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    private static function guardRecord(string $nonce): void
    {
        if (! current_user_can(Capabilities::RECORD_MEETING) && ! current_user_can(Capabilities::MANAGE_MEETINGS)) {
            wp_die(esc_html__('Du har inte behörighet att föra mötet.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    private static function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => 'foreningsplugin-meetings',
            'assoc_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    private static function noticeCode(MeetingRuleException $error): string
    {
        return match ($error->getMessage()) {
            'Only a planned meeting can be started.' => 'not_planned',
            'The meeting is already held.' => 'already_held',
            default => 'invalid',
        };
    }

    private static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';
        $messages = [
            'scheduled' => __('Mötet är sparat som planerat.', 'foreningsplugin'),
            'started' => __('Mötet pågår.', 'foreningsplugin'),
            'held' => __('Mötet är markerat som hållet.', 'foreningsplugin'),
            'not_planned' => __('Bara ett planerat möte kan startas.', 'foreningsplugin'),
            'already_held' => __('Mötet är redan hållet.', 'foreningsplugin'),
            'invalid' => __('Kontrollera uppgifterna och försök igen.', 'foreningsplugin'),
        ];

        if (! isset($messages[$notice])) {
            return;
        }

        $class = in_array($notice, ['scheduled', 'started', 'held'], true) ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }

    private static function typeLabel(MeetingType $type): string
    {
        return match ($type->slug()) {
            'board_meeting' => __('Styrelsemöte', 'foreningsplugin'),
            'annual_meeting' => __('Årsmöte', 'foreningsplugin'),
            'extraordinary_annual_meeting' => __('Extra årsmöte', 'foreningsplugin'),
            'member_meeting' => __('Medlemsmöte', 'foreningsplugin'),
            'working_meeting' => __('Arbetsmöte', 'foreningsplugin'),
            default => $type->name(),
        };
    }

    private static function statusLabel(MeetingStatus $status): string
    {
        return match ($status) {
            MeetingStatus::Planned => __('Planerat', 'foreningsplugin'),
            MeetingStatus::InProgress => __('Pågår', 'foreningsplugin'),
            MeetingStatus::Held => __('Hållet', 'foreningsplugin'),
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
