<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Person\PersonStatus;

final class MeetingDetailPage
{
    public static function addParticipant(): void
    {
        self::guard('assoc_add_participant');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::workspace()->addParticipant(
                $meetingId,
                self::integer('person_id'),
                Presence::from(self::text('presence')),
                MeetingDuty::from(self::text('meeting_duty') === '' ? MeetingDuty::None->value : self::text('meeting_duty'))
            );
            self::redirect($meetingId, 'participant_added');
        } catch (MeetingRuleException) {
            self::redirect($meetingId, 'duplicate_participant');
        } catch (\InvalidArgumentException | \RuntimeException | \ValueError) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function removeParticipant(): void
    {
        self::guard('assoc_remove_participant');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::workspace()->removeParticipant(self::integer('participant_id'));
            self::redirect($meetingId, 'participant_removed');
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function addAgendaItem(): void
    {
        self::guard('assoc_add_agenda_item');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::workspace()->addAgendaItem($meetingId, self::text('title'), self::text('number_override'));
            self::redirect($meetingId, 'agenda_added');
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function moveAgendaItem(): void
    {
        self::guard('assoc_move_agenda_item');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::workspace()->moveAgendaItem(self::integer('item_id'), self::integer('direction'));
            self::redirect($meetingId, 'agenda_moved');
        } catch (MeetingRuleException) {
            self::redirect($meetingId, 'cannot_move');
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function removeAgendaItem(): void
    {
        self::guard('assoc_remove_agenda_item');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::workspace()->removeAgendaItem(self::integer('item_id'));
            self::redirect($meetingId, 'agenda_removed');
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function render(int $meetingId): void
    {
        $meeting = self::meeting($meetingId);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($meeting instanceof Meeting ? $meeting->title() : __('Möte', 'foreningsplugin')) . '</h1>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=foreningsplugin-meetings')) . '">' . esc_html__('Alla möten', 'foreningsplugin') . '</a></p>';
        self::notice();

        if (! $meeting instanceof Meeting) {
            echo '<p>' . esc_html__('Mötet finns inte.', 'foreningsplugin') . '</p></div>';

            return;
        }

        $workspace = WordpressMeetings::workspace();
        $canEdit = current_user_can(Capabilities::MANAGE_MEETINGS) || current_user_can(Capabilities::RECORD_MEETING);
        echo '<p>' . esc_html($meeting->startsAt()->date() . ' ' . $meeting->startsAt()->time()) . '</p>';
        echo '<p>' . esc_html__('En adjungerad person behöver inte vara medlem. Listan visar namn, inte privat e-post.', 'foreningsplugin') . '</p>';

        if ($canEdit) {
            echo '<h2>' . esc_html__('Lägg till deltagare', 'foreningsplugin') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_add_participant">';
            echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
            wp_nonce_field('assoc_add_participant');
            echo '<p><label>' . esc_html__('Person', 'foreningsplugin') . ' <select name="person_id" required>';
            echo '<option value="">' . esc_html__('Välj person', 'foreningsplugin') . '</option>';

            foreach (WordpressPeople::service()->listPeople() as $record) {
                $person = $record->person();

                if ($person->id() === null) {
                    continue;
                }

                $name = $person->firstName() . ' ' . $person->lastName();

                if ($person->status() === PersonStatus::Deceased) {
                    $name .= ' (' . __('avliden', 'foreningsplugin') . ')';
                }

                echo '<option value="' . esc_attr((string) $person->id()) . '">' . esc_html($name) . '</option>';
            }

            echo '</select></label></p>';
            echo '<p><label>' . esc_html__('Närvaro', 'foreningsplugin') . ' <select name="presence">';
            foreach (Presence::cases() as $presence) {
                echo '<option value="' . esc_attr($presence->value) . '">' . esc_html(self::presenceLabel($presence)) . '</option>';
            }
            echo '</select></label></p>';
            echo '<p><label>' . esc_html__('Funktion', 'foreningsplugin') . ' <select name="meeting_duty">';
            foreach (MeetingDuty::cases() as $duty) {
                echo '<option value="' . esc_attr($duty->value) . '">' . esc_html(self::dutyLabel($duty)) . '</option>';
            }
            echo '</select></label></p>';
            submit_button(__('Lägg till deltagare', 'foreningsplugin'));
            echo '</form>';
        }

        echo '<h2>' . esc_html__('Deltagare', 'foreningsplugin') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Namn', 'foreningsplugin'), __('Närvaro', 'foreningsplugin'), __('Funktion', 'foreningsplugin')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        if ($canEdit) {
            echo '<th>' . esc_html__('Åtgärd', 'foreningsplugin') . '</th>';
        }
        echo '</tr></thead><tbody>';
        $attendance = $workspace->attendance($meetingId);

        if ($attendance === []) {
            echo '<tr><td colspan="4">' . esc_html__('Inga deltagare ännu.', 'foreningsplugin') . '</td></tr>';
        }

        foreach ($attendance as $row) {
            $participant = $row->participant();
            echo '<tr>';
            echo '<td>' . esc_html($row->personName()) . '</td>';
            echo '<td>' . esc_html(self::presenceLabel($participant->presence())) . '</td>';
            echo '<td>' . esc_html(self::dutyLabel($participant->duty())) . '</td>';

            if ($canEdit) {
                echo '<td>';
                self::postForm('assoc_remove_participant', $meetingId, [
                    'participant_id' => (string) $participant->id(),
                ], __('Ta bort deltagare', 'foreningsplugin'));
                echo '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($canEdit) {
            echo '<h2>' . esc_html__('Ny punkt', 'foreningsplugin') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_add_agenda_item">';
            echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
            wp_nonce_field('assoc_add_agenda_item');
            echo '<p><label>' . esc_html__('Punkt', 'foreningsplugin') . ' <input class="regular-text" type="text" name="title" required></label></p>';
            echo '<p><label>' . esc_html__('Nummer', 'foreningsplugin') . ' <input class="regular-text" type="text" name="number_override"></label></p>';
            submit_button(__('Lägg till punkt', 'foreningsplugin'));
            echo '</form>';
        }

        echo '<h2>' . esc_html__('Dagordning', 'foreningsplugin') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Nr', 'foreningsplugin'), __('Punkt', 'foreningsplugin')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        if ($canEdit) {
            echo '<th>' . esc_html__('Åtgärd', 'foreningsplugin') . '</th>';
        }
        echo '</tr></thead><tbody>';
        $agenda = $workspace->agenda($meetingId);

        if ($agenda === []) {
            echo '<tr><td colspan="3">' . esc_html__('Ingen dagordning ännu.', 'foreningsplugin') . '</td></tr>';
        }

        foreach ($agenda as $item) {
            echo '<tr>';
            echo '<td>' . esc_html($item->displayNumber()) . '</td>';
            echo '<td>' . esc_html($item->title()) . '</td>';

            if ($canEdit) {
                echo '<td>';
                self::postForm('assoc_move_agenda_item', $meetingId, [
                    'item_id' => (string) $item->id(),
                    'direction' => '-1',
                ], __('Upp', 'foreningsplugin'));
                self::postForm('assoc_move_agenda_item', $meetingId, [
                    'item_id' => (string) $item->id(),
                    'direction' => '1',
                ], __('Ner', 'foreningsplugin'));
                self::postForm('assoc_remove_agenda_item', $meetingId, [
                    'item_id' => (string) $item->id(),
                ], __('Ta bort punkt', 'foreningsplugin'));
                echo '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private static function meeting(int $meetingId): ?Meeting
    {
        foreach (WordpressMeetings::service()->listMeetings() as $meeting) {
            if ($meeting->id() === $meetingId) {
                return $meeting;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $fields
     */
    private static function postForm(string $action, int $meetingId, array $fields, string $label): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:0.5em">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';

        foreach ($fields as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }

        wp_nonce_field($action);
        submit_button($label, 'secondary', 'submit', false);
        echo '</form>';
    }

    private static function guard(string $nonce): void
    {
        if (! current_user_can(Capabilities::MANAGE_MEETINGS) && ! current_user_can(Capabilities::RECORD_MEETING)) {
            wp_die(esc_html__('Du har inte behörighet att ändra mötet.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    private static function redirect(int $meetingId, string $notice): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => 'foreningsplugin-meetings',
            'meeting' => $meetingId,
            'assoc_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    private static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';
        $messages = [
            'participant_added' => __('Deltagaren är tillagd.', 'foreningsplugin'),
            'participant_removed' => __('Deltagaren är borttagen från mötet.', 'foreningsplugin'),
            'duplicate_participant' => __('Personen finns redan på mötet.', 'foreningsplugin'),
            'agenda_added' => __('Punkten är tillagd.', 'foreningsplugin'),
            'agenda_moved' => __('Dagordningen är omordnad.', 'foreningsplugin'),
            'agenda_removed' => __('Punkten är borttagen och numren är räknade om.', 'foreningsplugin'),
            'cannot_move' => __('Punkten kan inte flyttas åt det hållet.', 'foreningsplugin'),
            'invalid' => __('Kontrollera uppgifterna och försök igen.', 'foreningsplugin'),
        ];

        if (! isset($messages[$notice])) {
            return;
        }

        $class = in_array($notice, ['participant_added', 'participant_removed', 'agenda_added', 'agenda_moved', 'agenda_removed'], true)
            ? 'notice-success'
            : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }

    private static function presenceLabel(Presence $presence): string
    {
        return match ($presence) {
            Presence::Present => __('Närvarande', 'foreningsplugin'),
            Presence::Absent => __('Frånvarande', 'foreningsplugin'),
            Presence::CoOpted => __('Adjungerad', 'foreningsplugin'),
        };
    }

    private static function dutyLabel(MeetingDuty $duty): string
    {
        return match ($duty) {
            MeetingDuty::None => __('Ingen', 'foreningsplugin'),
            MeetingDuty::Chair => __('Ordförande', 'foreningsplugin'),
            MeetingDuty::Adjuster => __('Justerare', 'foreningsplugin'),
        };
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
