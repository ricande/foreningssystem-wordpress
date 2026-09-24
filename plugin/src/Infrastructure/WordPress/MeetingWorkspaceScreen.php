<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Meeting\ActionItemRow;
use Foreningssystem\Application\Meeting\DecisionRow;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\AgendaItem;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingNote;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MeetingType;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Person\PersonStatus;

final class MeetingWorkspaceScreen
{
    public static function render(int $meetingId): void
    {
        $meeting = self::meeting($meetingId);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($meeting instanceof Meeting ? $meeting->title() : __('Meeting', 'foreningsplugin')) . '</h1>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=foreningsplugin-meetings')) . '">' . esc_html__('All meetings', 'foreningsplugin') . '</a></p>';
        MeetingDetailPage::notice();

        if (! $meeting instanceof Meeting) {
            echo '<p>' . esc_html__('The meeting does not exist.', 'foreningsplugin') . '</p></div>';

            return;
        }

        $types = [];

        foreach (WordpressMeetings::service()->types() as $type) {
            if ($type->id() !== null) {
                $types[$type->id()] = $type;
            }
        }

        $type = $types[$meeting->typeId()] ?? null;
        $canEdit = current_user_can(Capabilities::MANAGE_MEETINGS) || current_user_can(Capabilities::RECORD_MEETING);
        $canRecord = current_user_can(Capabilities::RECORD_MEETING);
        $canFinalize = current_user_can(Capabilities::FINALIZE_MINUTES);
        $record = WordpressMeetings::record();
        $workspace = WordpressMeetings::workspace();
        $notes = $record->notes($meetingId);
        $decisionRows = $record->decisions($meetingId);
        $actionRows = $record->actionItems($meetingId);
        $attendance = $workspace->attendance($meetingId);
        $agenda = $workspace->agenda($meetingId);
        $people = self::livingPeople();
        $draft = $meeting->status() === MeetingStatus::Held ? WordpressMeetings::minutes()->current($meetingId) : null;
        $focus = isset($_GET['agenda']) ? absint($_GET['agenda']) : 0;

        if ($focus < 1 && $meeting->status() === MeetingStatus::InProgress && $agenda !== []) {
            $focus = (int) $agenda[0]->id();
        }

        self::header($meeting, $type instanceof MeetingType ? $type : null, $canEdit, $canRecord, $draft, $types);
        echo '<p>' . esc_html__('An adjunct person does not have to be a member. The list shows names, not private email.', 'foreningsplugin') . '</p>';

        if ($meeting->status() === MeetingStatus::InProgress) {
            self::agenda($meeting, $agenda, $notes, $decisionRows, $actionRows, $people, $canEdit, $canRecord, $focus);
            echo '<details><summary>' . esc_html__('Participants', 'foreningsplugin') . '</summary>';
            self::participants($meetingId, $attendance, $people, $canEdit);
            echo '</details>';
        } else {
            self::participants($meetingId, $attendance, $people, $canEdit);
            self::agenda($meeting, $agenda, $notes, $decisionRows, $actionRows, $people, $canEdit, $canRecord, $focus);
        }

        $prominent = $meeting->status() === MeetingStatus::Held;
        echo $prominent ? '<div id="assoc-minutes">' : '<details id="assoc-minutes"><summary>' . esc_html__('Minutes', 'foreningsplugin') . '</summary>';
        MeetingMinutesScreen::render($meeting, $canRecord, $canFinalize, $draft);
        echo $prominent ? '' : '</details>';
        echo '</div>';
    }

    /**
     * @param array<int, MeetingType> $types
     */
    private static function header(
        Meeting $meeting,
        ?MeetingType $type,
        bool $canEdit,
        bool $canRecord,
        ?MinutesRevision $draft,
        array $types,
    ): void {
        $meetingId = (int) $meeting->id();
        echo '<p>';
        echo esc_html($type instanceof MeetingType ? MeetingLabels::type($type) : '');
        echo ' · ' . esc_html($meeting->startsAt()->date() . ' ' . $meeting->startsAt()->time());

        if ($meeting->place() !== '') {
            echo ' · ' . esc_html($meeting->place());
        }

        echo ' · <strong>' . esc_html(MeetingLabels::status($meeting->status())) . '</strong>';
        echo '</p>';
        echo '<p id="assoc-meeting-next">';
        self::primaryAction($meeting, $canEdit, $canRecord, $draft);
        echo '</p>';

        if (! $canEdit) {
            return;
        }

        echo '<details><summary>' . esc_html__('Edit meeting details', 'foreningsplugin') . '</summary>';
        MeetingAdminForms::begin('assoc_save_meeting_header', $meetingId);
        echo '<p><label>' . esc_html__('Type', 'foreningsplugin') . ' <select name="type_id" required>';

        foreach ($types as $option) {
            $selected = $option->id() === $meeting->typeId() ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $option->id()) . '"' . $selected . '>' . esc_html(MeetingLabels::type($option)) . '</option>';
        }

        echo '</select></label></p>';
        echo '<p><label>' . esc_html__('Title', 'foreningsplugin') . ' <input class="regular-text" type="text" name="title" value="' . esc_attr($meeting->title()) . '" required></label></p>';
        echo '<p><label>' . esc_html__('Date', 'foreningsplugin') . ' <input type="date" name="meeting_date" value="' . esc_attr($meeting->startsAt()->date()) . '" required></label></p>';
        echo '<p><label>' . esc_html__('Time', 'foreningsplugin') . ' <input type="time" name="meeting_time" value="' . esc_attr($meeting->startsAt()->time()) . '" required></label></p>';
        echo '<p><label>' . esc_html__('Place', 'foreningsplugin') . ' <input class="regular-text" type="text" name="place" value="' . esc_attr($meeting->place()) . '"></label></p>';
        submit_button(__('Save meeting details', 'foreningsplugin'), 'secondary');
        echo '</form></details>';
    }

    private static function primaryAction(Meeting $meeting, bool $canEdit, bool $canRecord, ?MinutesRevision $draft): void
    {
        $meetingId = (int) $meeting->id();

        if ($meeting->status() === MeetingStatus::Planned && $canEdit) {
            self::lifecycleForm($meetingId, 'assoc_start_meeting', __('Start meeting', 'foreningsplugin'), 'primary', null);
            echo '<details><summary>' . esc_html__('Record afterwards', 'foreningsplugin') . '</summary>';
            echo '<p>' . esc_html__('Mark this meeting as held? Notes, decisions and tasks remain available for minutes preparation.', 'foreningsplugin') . '</p>';
            self::lifecycleForm($meetingId, 'assoc_mark_meeting_held', __('Mark meeting as held', 'foreningsplugin'), 'secondary', __('Mark this meeting as held', 'foreningsplugin'));
            echo '</details>';

            return;
        }

        if ($meeting->status() === MeetingStatus::InProgress && $canEdit) {
            echo '<p>' . esc_html__('Mark this meeting as held? Notes, decisions and tasks remain available for minutes preparation.', 'foreningsplugin') . '</p>';
            self::lifecycleForm($meetingId, 'assoc_mark_meeting_held', __('Mark meeting as held', 'foreningsplugin'), 'primary', __('Mark this meeting as held', 'foreningsplugin'));

            return;
        }

        if ($meeting->status() !== MeetingStatus::Held) {
            return;
        }

        if (! $draft instanceof MinutesRevision && $canRecord) {
            MeetingAdminForms::begin('assoc_create_minutes_draft', $meetingId);
            echo '<p>' . esc_html__('The draft is composed from the meeting, attendance, agenda, notes marked for inclusion, decisions, and tasks.', 'foreningsplugin') . '</p>';
            submit_button(__('Create minutes draft', 'foreningsplugin'), 'primary');
            echo '</form>';

            return;
        }

        if ($draft instanceof MinutesRevision) {
            echo '<a class="button button-primary" href="#assoc-minutes">' . esc_html__('Review minutes', 'foreningsplugin') . '</a>';
        }
    }

    private static function lifecycleForm(int $meetingId, string $action, string $label, string $style, ?string $confirm): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:0.75em">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
        echo '<input type="hidden" name="return_meeting" value="' . esc_attr((string) $meetingId) . '">';
        wp_nonce_field($action);

        if ($confirm !== null) {
            echo '<label><input type="checkbox" name="confirm" value="1" required> ' . esc_html($confirm) . '</label> ';
        }

        submit_button($label, $style, 'submit', false);
        echo '</form>';
    }

    /**
     * @param list<\Foreningssystem\Application\Meeting\AttendanceRow> $attendance
     * @param array<int, string> $people
     */
    private static function participants(int $meetingId, array $attendance, array $people, bool $canEdit): void
    {
        echo '<h2 id="assoc-participants">' . esc_html__('Participants', 'foreningsplugin') . '</h2>';

        if ($canEdit) {
            MeetingAdminForms::begin('assoc_add_participant', $meetingId);
            echo '<p><label>' . esc_html__('Person', 'foreningsplugin') . ' <select name="person_id" required>';
            echo '<option value="">' . esc_html__('Choose person', 'foreningsplugin') . '</option>';

            foreach ($people as $id => $name) {
                echo '<option value="' . esc_attr((string) $id) . '">' . esc_html($name) . '</option>';
            }

            echo '</select></label></p>';
            echo '<p><label>' . esc_html__('Attendance', 'foreningsplugin') . ' <select name="presence">';

            foreach (Presence::cases() as $presence) {
                echo '<option value="' . esc_attr($presence->value) . '">' . esc_html(MeetingLabels::presence($presence)) . '</option>';
            }

            echo '</select></label></p>';
            echo '<p><label>' . esc_html__('Duty', 'foreningsplugin') . ' <select name="meeting_duty">';

            foreach (MeetingDuty::cases() as $duty) {
                echo '<option value="' . esc_attr($duty->value) . '">' . esc_html(MeetingLabels::duty($duty)) . '</option>';
            }

            echo '</select></label></p>';
            submit_button(__('Add participant', 'foreningsplugin'), 'secondary');
            echo '</form>';
        }

        echo '<table class="widefat striped"><thead><tr>';

        foreach ([__('Name', 'foreningsplugin'), __('Attendance', 'foreningsplugin'), __('Duty', 'foreningsplugin')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }

        if ($canEdit) {
            echo '<th>' . esc_html__('Action', 'foreningsplugin') . '</th>';
        }

        echo '</tr></thead><tbody>';

        if ($attendance === []) {
            echo '<tr><td colspan="4">' . esc_html__('No participants yet.', 'foreningsplugin') . '</td></tr>';
        }

        foreach ($attendance as $row) {
            $participant = $row->participant();
            echo '<tr>';
            echo '<td>' . esc_html($row->personName()) . '</td>';
            echo '<td>' . esc_html(MeetingLabels::presence($participant->presence())) . '</td>';
            echo '<td>' . esc_html(MeetingLabels::duty($participant->duty())) . '</td>';

            if ($canEdit) {
                echo '<td>';
                MeetingAdminForms::post('assoc_remove_participant', $meetingId, [
                    'participant_id' => (string) $participant->id(),
                ], __('Remove participant', 'foreningsplugin'), __('Remove this participant from the meeting', 'foreningsplugin'));
                echo '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param list<AgendaItem> $agenda
     * @param list<MeetingNote> $notes
     * @param list<DecisionRow> $decisionRows
     * @param list<ActionItemRow> $actionRows
     * @param array<int, string> $people
     */
    private static function agenda(
        Meeting $meeting,
        array $agenda,
        array $notes,
        array $decisionRows,
        array $actionRows,
        array $people,
        bool $canEdit,
        bool $canRecord,
        int $focus,
    ): void {
        $meetingId = (int) $meeting->id();
        echo '<h2>' . esc_html__('Agenda', 'foreningsplugin') . '</h2>';

        if ($meeting->status() === MeetingStatus::InProgress && $agenda !== []) {
            echo '<nav aria-label="' . esc_attr__('Agenda', 'foreningsplugin') . '"><ul>';

            foreach ($agenda as $item) {
                $url = add_query_arg([
                    'page' => 'foreningsplugin-meetings',
                    'meeting' => $meetingId,
                    'agenda' => (int) $item->id(),
                ], admin_url('admin.php')) . '#agenda-' . (int) $item->id();
                echo '<li><a href="' . esc_url($url) . '">' . esc_html($item->displayNumber() . '. ' . $item->title()) . '</a></li>';
            }

            echo '</ul></nav>';
        }

        if ($canEdit) {
            MeetingAdminForms::begin('assoc_add_agenda_item', $meetingId);
            echo '<p><label>' . esc_html__('Item', 'foreningsplugin') . ' <input class="regular-text" type="text" name="title" required></label></p>';
            echo '<p><label>' . esc_html__('Number', 'foreningsplugin') . ' <input class="regular-text" type="text" name="number_override"> <span class="description">' . esc_html__('Leave empty to use the position number.', 'foreningsplugin') . '</span></label></p>';
            submit_button(__('Add item', 'foreningsplugin'), 'secondary');
            echo '</form>';
        }

        if ($agenda === []) {
            echo '<p>' . esc_html__('No agenda yet.', 'foreningsplugin') . '</p>';
        }

        echo '<div id="assoc-agenda">';
        echo '<style>#assoc-agenda > details{margin:0.75em 0;padding:0.25em 0 0.25em 0.75em}#assoc-agenda > details[open]{border-left:4px solid #2271b1}</style>';

        if ($canRecord) {
            echo '<details' . ($focus < 1 && $meeting->status() !== MeetingStatus::InProgress ? ' open' : '') . '><summary>' . esc_html__('Meeting notes', 'foreningsplugin') . '</summary>';
            self::capture($meetingId, null, $notes, $decisionRows, $actionRows, $people, $canRecord);
            echo '</details>';
        } else {
            self::capture($meetingId, null, $notes, $decisionRows, $actionRows, $people, false);
        }

        foreach ($agenda as $item) {
            $itemId = (int) $item->id();
            $open = $itemId === $focus ? ' open' : '';
            echo '<details id="agenda-' . esc_attr((string) $itemId) . '"' . $open . '>';
            echo '<summary><strong>' . esc_html($item->displayNumber() . '. ' . $item->title()) . '</strong></summary>';

            if ($canEdit) {
                MeetingAdminForms::post('assoc_move_agenda_item', $meetingId, [
                    'item_id' => (string) $itemId,
                    'direction' => '-1',
                ], __('Move up', 'foreningsplugin'));
                MeetingAdminForms::post('assoc_move_agenda_item', $meetingId, [
                    'item_id' => (string) $itemId,
                    'direction' => '1',
                ], __('Move down', 'foreningsplugin'));
                MeetingAdminForms::post('assoc_remove_agenda_item', $meetingId, [
                    'item_id' => (string) $itemId,
                ], __('Remove agenda item', 'foreningsplugin'), __('Remove this agenda item', 'foreningsplugin'));
            }

            self::capture($meetingId, $itemId, $notes, $decisionRows, $actionRows, $people, $canRecord);
            echo '</details>';
        }

        echo '</div>';
        echo '<script>document.querySelectorAll("#assoc-agenda > details").forEach(function(item){item.addEventListener("toggle",function(){if(!item.open){return;}document.querySelectorAll("#assoc-agenda > details").forEach(function(other){if(other!==item){other.open=false;}});});});</script>';
    }

    /**
     * @param list<MeetingNote> $notes
     * @param list<DecisionRow> $decisionRows
     * @param list<ActionItemRow> $actionRows
     * @param array<int, string> $people
     */
    private static function capture(
        int $meetingId,
        ?int $agendaItemId,
        array $notes,
        array $decisionRows,
        array $actionRows,
        array $people,
        bool $canRecord,
    ): void {
        $fields = $agendaItemId === null ? [] : ['agenda_item_id' => (string) $agendaItemId];

        foreach ($notes as $note) {
            if ($note->agendaItemId() !== $agendaItemId) {
                continue;
            }

            echo '<p><strong>' . esc_html($note->includeInMinutes() ? __('Include in minutes', 'foreningsplugin') : __('Working note', 'foreningsplugin')) . '</strong><br>';
            echo esc_html($note->body()) . '</p>';

            if ($canRecord) {
                MeetingAdminForms::post('assoc_remove_note', $meetingId, $fields + [
                    'note_id' => (string) $note->id(),
                ], __('Remove note', 'foreningsplugin'), __('Remove this note', 'foreningsplugin'));
            }
        }

        foreach ($decisionRows as $row) {
            $decision = $row->decision();

            if ($decision->agendaItemId() !== $agendaItemId) {
                continue;
            }

            echo '<p><strong>' . esc_html__('Decision', 'foreningsplugin') . '</strong> ';
            echo esc_html($decision->followUp() === DecisionFollowUp::Done ? __('Done', 'foreningsplugin') : __('Open', 'foreningsplugin'));

            if ($row->responsibleName() !== null) {
                echo ' · ' . esc_html($row->responsibleName());
            }

            if ($decision->deadline() !== null) {
                echo ' · ' . esc_html($decision->deadline()->iso());
            }

            echo '<br>' . esc_html($decision->wording()) . '</p>';

            if ($canRecord) {
                $next = $decision->followUp() === DecisionFollowUp::Open ? DecisionFollowUp::Done : DecisionFollowUp::Open;
                MeetingAdminForms::post('assoc_set_decision_follow_up', $meetingId, $fields + [
                    'decision_id' => (string) $decision->id(),
                    'follow_up' => $next->value,
                ], $next === DecisionFollowUp::Done ? __('Mark follow-up done', 'foreningsplugin') : __('Reopen follow-up', 'foreningsplugin'));
                MeetingAdminForms::post('assoc_remove_decision', $meetingId, $fields + [
                    'decision_id' => (string) $decision->id(),
                ], __('Remove decision', 'foreningsplugin'), __('Remove this decision', 'foreningsplugin'));
            }
        }

        foreach ($actionRows as $actionRow) {
            $action = $actionRow->item();

            if ($action->agendaItemId() !== $agendaItemId) {
                continue;
            }

            echo '<p><strong>' . esc_html__('Task', 'foreningsplugin') . '</strong> ';
            echo esc_html($action->status() === ActionStatus::Done ? __('Done', 'foreningsplugin') : __('Open', 'foreningsplugin'));

            if ($actionRow->assigneeName() !== null) {
                echo ' · ' . esc_html($actionRow->assigneeName());
            }

            if ($action->dueOn() !== null) {
                echo ' · ' . esc_html($action->dueOn()->iso());
            }

            echo '<br>' . esc_html($action->task()) . '</p>';

            if ($canRecord) {
                $next = $action->status() === ActionStatus::Open ? ActionStatus::Done : ActionStatus::Open;
                MeetingAdminForms::post('assoc_set_action_status', $meetingId, $fields + [
                    'action_item_id' => (string) $action->id(),
                    'status' => $next->value,
                ], $next === ActionStatus::Done ? __('Mark task done', 'foreningsplugin') : __('Reopen task', 'foreningsplugin'));
                MeetingAdminForms::post('assoc_remove_action_item', $meetingId, $fields + [
                    'action_item_id' => (string) $action->id(),
                ], __('Remove task', 'foreningsplugin'), __('Remove this task', 'foreningsplugin'));
            }
        }

        if (! $canRecord) {
            return;
        }

        $hidden = '';

        foreach ($fields as $name => $value) {
            $hidden .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }

        echo '<details><summary>' . esc_html__('Add note', 'foreningsplugin') . '</summary>';
        MeetingAdminForms::begin('assoc_add_note', $meetingId);
        echo $hidden;
        echo '<p><label>' . esc_html__('Working note', 'foreningsplugin') . '<br><textarea class="large-text" name="body" rows="3" required></textarea></label></p>';
        echo '<p><label><input type="checkbox" name="include_in_minutes" value="1"> ' . esc_html__('Include in minutes', 'foreningsplugin') . '</label></p>';
        echo '<p class="description">' . esc_html__('A working note stays with the meeting. Only a note marked for inclusion is copied into the minutes draft.', 'foreningsplugin') . '</p>';
        submit_button(__('Add note', 'foreningsplugin'), 'secondary');
        echo '</form></details>';

        echo '<details><summary>' . esc_html__('Add decision', 'foreningsplugin') . '</summary>';
        MeetingAdminForms::begin('assoc_add_decision', $meetingId);
        echo $hidden;
        echo '<p><label>' . esc_html__('Decision', 'foreningsplugin') . '<br><textarea class="large-text" name="wording" rows="3" required></textarea></label></p>';
        MeetingAdminForms::personSelect('responsible_person_id', $people, __('Responsible', 'foreningsplugin'));
        echo '<p><label>' . esc_html__('Deadline', 'foreningsplugin') . ' <input type="date" name="deadline"></label></p>';
        submit_button(__('Add decision', 'foreningsplugin'), 'secondary');
        echo '</form></details>';

        echo '<details><summary>' . esc_html__('Add task', 'foreningsplugin') . '</summary>';
        MeetingAdminForms::begin('assoc_add_action_item', $meetingId);
        echo $hidden;
        echo '<p><label>' . esc_html__('Task', 'foreningsplugin') . '<br><textarea class="large-text" name="task" rows="3" required></textarea></label></p>';
        MeetingAdminForms::personSelect('assignee_person_id', $people, __('Assignee', 'foreningsplugin'));
        echo '<p><label>' . esc_html__('Due date', 'foreningsplugin') . ' <input type="date" name="due_on"></label></p>';
        echo '<p class="description">' . esc_html__('A task is something to be done. It is not the same as a decision.', 'foreningsplugin') . '</p>';
        submit_button(__('Add task', 'foreningsplugin'), 'secondary');
        echo '</form></details>';
    }

    /**
     * @return array<int, string>
     */
    private static function livingPeople(): array
    {
        $people = [];

        foreach (WordpressPeople::service()->listPeople() as $record) {
            $person = $record->person();

            if ($person->id() === null || $person->status() === PersonStatus::Deceased) {
                continue;
            }

            $people[$person->id()] = $person->firstName() . ' ' . $person->lastName();
        }

        asort($people);

        return $people;
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
}
