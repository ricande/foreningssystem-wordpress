<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Membership\AssociationDate;
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

    public static function addNote(): void
    {
        self::guardRecord('assoc_add_note');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::record()->addNote(
                $meetingId,
                self::optionalInteger('agenda_item_id'),
                self::textarea('body'),
                self::checked('include_in_minutes')
            );
            self::redirect($meetingId, 'note_added');
        } catch (MeetingRuleException) {
            self::redirect($meetingId, 'wrong_item');
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function removeNote(): void
    {
        self::guardRecord('assoc_remove_note');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::record()->removeNote(self::integer('note_id'));
            self::redirect($meetingId, 'note_removed');
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function addDecision(): void
    {
        self::guardRecord('assoc_add_decision');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::record()->addDecision(
                $meetingId,
                self::optionalInteger('agenda_item_id'),
                self::textarea('wording'),
                self::optionalInteger('responsible_person_id'),
                self::optionalDate('deadline')
            );
            self::redirect($meetingId, 'decision_added');
        } catch (MeetingRuleException) {
            self::redirect($meetingId, 'wrong_item');
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function setDecisionFollowUp(): void
    {
        self::guardRecord('assoc_set_decision_follow_up');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::record()->setFollowUp(
                self::integer('decision_id'),
                DecisionFollowUp::from(self::text('follow_up'))
            );
            self::redirect($meetingId, 'follow_up');
        } catch (\ValueError | \RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function removeDecision(): void
    {
        self::guardRecord('assoc_remove_decision');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::record()->removeDecision(self::integer('decision_id'));
            self::redirect($meetingId, 'decision_removed');
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
        $record = WordpressMeetings::record();
        $notes = $record->notes($meetingId);
        $decisionRows = $record->decisions($meetingId);
        $canEdit = current_user_can(Capabilities::MANAGE_MEETINGS) || current_user_can(Capabilities::RECORD_MEETING);
        $canRecord = current_user_can(Capabilities::RECORD_MEETING);
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

        echo '<h2>' . esc_html__('Anteckningar om mötet', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('Anteckningar är arbetsmaterial, inte protokollet. Markera det som ska tas med senare.', 'foreningsplugin') . '</p>';
        self::renderCapture($meetingId, null, $notes, $decisionRows, $canRecord);

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

            echo '</tr><tr><td colspan="3">';
            self::renderCapture($meetingId, $item->id(), $notes, $decisionRows, $canRecord);
            echo '</td></tr>';
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
            'note_added' => __('Anteckningen är sparad.', 'foreningsplugin'),
            'note_removed' => __('Anteckningen är borttagen.', 'foreningsplugin'),
            'decision_added' => __('Beslutet är sparat.', 'foreningsplugin'),
            'follow_up' => __('Uppföljningen är ändrad. Beslutets lydelse är densamma.', 'foreningsplugin'),
            'decision_removed' => __('Beslutet är borttaget.', 'foreningsplugin'),
            'wrong_item' => __('Punkten hör inte till det här mötet.', 'foreningsplugin'),
            'invalid' => __('Kontrollera uppgifterna och försök igen.', 'foreningsplugin'),
        ];

        if (! isset($messages[$notice])) {
            return;
        }

        $class = in_array($notice, ['participant_added', 'participant_removed', 'agenda_added', 'agenda_moved', 'agenda_removed', 'note_added', 'note_removed', 'decision_added', 'follow_up', 'decision_removed'], true)
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

    private static function optionalInteger(string $key): ?int
    {
        $value = self::integer($key);

        return $value > 0 ? $value : null;
    }

    private static function optionalDate(string $key): ?AssociationDate
    {
        $value = self::text($key);

        return $value === '' ? null : AssociationDate::fromIso($value);
    }

    private static function textarea(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? sanitize_textarea_field(wp_unslash($value)) : '';
    }

    private static function checked(string $key): bool
    {
        return isset($_POST[$key]);
    }

    private static function guardRecord(string $nonce): void
    {
        if (! current_user_can(Capabilities::RECORD_MEETING)) {
            wp_die(esc_html__('Du har inte behörighet att föra anteckningar eller beslut.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    /**
     * @param list<\Foreningssystem\Domain\Meeting\MeetingNote> $notes
     * @param list<\Foreningssystem\Application\Meeting\DecisionRow> $decisionRows
     */
    private static function renderCapture(int $meetingId, ?int $agendaItemId, array $notes, array $decisionRows, bool $canRecord): void
    {
        foreach ($notes as $note) {
            if ($note->agendaItemId() !== $agendaItemId) {
                continue;
            }

            echo '<p><strong>' . esc_html__('Anteckning', 'foreningsplugin') . '</strong> ';
            echo esc_html($note->includeInMinutes() ? __('Tas med i protokollet', 'foreningsplugin') : __('Arbetsanteckning', 'foreningsplugin'));
            echo '<br>' . esc_html($note->body()) . '</p>';

            if ($canRecord) {
                self::postForm('assoc_remove_note', $meetingId, [
                    'note_id' => (string) $note->id(),
                ], __('Ta bort anteckning', 'foreningsplugin'));
            }
        }

        foreach ($decisionRows as $row) {
            $decision = $row->decision();

            if ($decision->agendaItemId() !== $agendaItemId) {
                continue;
            }

            $meta = $decision->followUp() === DecisionFollowUp::Done
                ? __('Klar', 'foreningsplugin')
                : __('Öppen', 'foreningsplugin');

            if ($row->responsibleName() !== null) {
                $meta .= ', ' . $row->responsibleName();
            }

            if ($decision->deadline() !== null) {
                $meta .= ', ' . $decision->deadline()->iso();
            }

            echo '<p><strong>' . esc_html__('Beslut', 'foreningsplugin') . '</strong> ' . esc_html($meta);
            echo '<br>' . esc_html($decision->wording()) . '</p>';

            if ($canRecord) {
                $next = $decision->followUp() === DecisionFollowUp::Open ? DecisionFollowUp::Done : DecisionFollowUp::Open;
                $label = $next === DecisionFollowUp::Done
                    ? __('Markera som klar', 'foreningsplugin')
                    : __('Återöppna', 'foreningsplugin');
                self::postForm('assoc_set_decision_follow_up', $meetingId, [
                    'decision_id' => (string) $decision->id(),
                    'follow_up' => $next->value,
                ], $label);
                self::postForm('assoc_remove_decision', $meetingId, [
                    'decision_id' => (string) $decision->id(),
                ], __('Ta bort beslut', 'foreningsplugin'));
            }
        }

        if (! $canRecord) {
            return;
        }

        $itemField = $agendaItemId === null ? '' : '<input type="hidden" name="agenda_item_id" value="' . esc_attr((string) $agendaItemId) . '">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_add_note">';
        echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
        echo $itemField;
        wp_nonce_field('assoc_add_note');
        echo '<p><label>' . esc_html__('Anteckning', 'foreningsplugin') . '<br><textarea class="large-text" name="body" rows="3" required></textarea></label></p>';
        echo '<p><label><input type="checkbox" name="include_in_minutes" value="1"> ' . esc_html__('Ta med i protokollet', 'foreningsplugin') . '</label></p>';
        submit_button(__('Spara anteckning', 'foreningsplugin'), 'secondary');
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_add_decision">';
        echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
        echo $itemField;
        wp_nonce_field('assoc_add_decision');
        echo '<p><label>' . esc_html__('Beslut', 'foreningsplugin') . '<br><textarea class="large-text" name="wording" rows="3" required></textarea></label></p>';
        echo '<p><label>' . esc_html__('Ansvarig', 'foreningsplugin') . ' <select name="responsible_person_id">';
        echo '<option value="">' . esc_html__('Ingen', 'foreningsplugin') . '</option>';

        foreach (WordpressPeople::service()->listPeople() as $personRecord) {
            $person = $personRecord->person();

            if ($person->id() === null) {
                continue;
            }

            echo '<option value="' . esc_attr((string) $person->id()) . '">' . esc_html($person->firstName() . ' ' . $person->lastName()) . '</option>';
        }

        echo '</select></label></p>';
        echo '<p><label>' . esc_html__('Deadline', 'foreningsplugin') . ' <input type="date" name="deadline"></label></p>';
        submit_button(__('Spara beslut', 'foreningsplugin'), 'secondary');
        echo '</form>';
    }
}
