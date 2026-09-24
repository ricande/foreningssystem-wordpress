<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
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

    public static function addActionItem(): void
    {
        self::guardRecord('assoc_add_action_item');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::record()->addActionItem(
                $meetingId,
                self::optionalInteger('agenda_item_id'),
                self::textarea('task'),
                self::optionalInteger('assignee_person_id'),
                self::optionalDate('due_on')
            );
            self::redirect($meetingId, 'action_added');
        } catch (MeetingRuleException) {
            self::redirect($meetingId, 'wrong_item');
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function setActionStatus(): void
    {
        self::guardRecord('assoc_set_action_status');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::record()->setActionStatus(
                self::integer('action_item_id'),
                ActionStatus::from(self::text('status'))
            );
            self::redirect($meetingId, 'action_status');
        } catch (\ValueError | \RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function removeActionItem(): void
    {
        self::guardRecord('assoc_remove_action_item');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::record()->removeActionItem(self::integer('action_item_id'));
            self::redirect($meetingId, 'action_removed');
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function createMinutesDraft(): void
    {
        self::guardRecord('assoc_create_minutes_draft');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::minutes()->create($meetingId);
            self::redirect($meetingId, 'draft_created');
        } catch (MeetingRuleException) {
            self::redirect($meetingId, 'draft_blocked');
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function replaceMinutesBody(): void
    {
        self::guardRecord('assoc_replace_minutes_body');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::minutes()->replaceBody(self::integer('revision_id'), self::textarea('body'));
            self::redirect($meetingId, 'draft_saved');
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function regenerateMinutesDraft(): void
    {
        self::guardRecord('assoc_regenerate_minutes_draft');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::minutes()->regenerate(self::integer('revision_id'), self::text('confirmed') === '1');
            self::redirect($meetingId, 'draft_regenerated');
        } catch (MeetingRuleException) {
            self::redirect($meetingId, 'confirm_regenerate');
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function submitMinutes(): void
    {
        self::guardRecord('assoc_submit_minutes');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::minutes()->submit(self::integer('revision_id'));
            self::redirect($meetingId, 'minutes_submitted');
        } catch (MeetingRuleException) {
            self::redirect($meetingId, 'minutes_blocked');
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function sendMinutesBack(): void
    {
        self::guardRecord('assoc_send_minutes_back');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::minutes()->sendBack(self::integer('revision_id'));
            self::redirect($meetingId, 'minutes_returned');
        } catch (MeetingRuleException) {
            self::redirect($meetingId, 'minutes_blocked');
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function finalizeMinutes(): void
    {
        self::guardFinalize('assoc_finalize_minutes');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::minutes()->finalize(self::integer('revision_id'));
            self::redirect($meetingId, 'minutes_finalized');
        } catch (MeetingRuleException) {
            self::redirect($meetingId, 'minutes_blocked');
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function publishMinutes(): void
    {
        self::guardPublish('assoc_publish_minutes');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::publication()->publish(self::integer('revision_id'));
            self::redirect($meetingId, 'minutes_published');
        } catch (MeetingRuleException) {
            self::redirect($meetingId, 'minutes_blocked');
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function unpublishMinutes(): void
    {
        self::guardPublish('assoc_unpublish_minutes');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::publication()->unpublish(self::integer('revision_id'));
            self::redirect($meetingId, 'minutes_unpublished');
        } catch (MeetingRuleException) {
            self::redirect($meetingId, 'minutes_blocked');
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function openMinutesCorrection(): void
    {
        self::guardFinalize('assoc_open_minutes_correction');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::minutes()->openCorrection($meetingId);
            self::redirect($meetingId, 'minutes_correction');
        } catch (MeetingRuleException) {
            self::redirect($meetingId, 'minutes_blocked');
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function downloadMinutesPdf(): void
    {
        $revisionId = self::queryInteger('revision_id');
        check_admin_referer('assoc_download_minutes_pdf');

        try {
            $pdf = WordpressMeetings::pdf();
            $revision = $pdf->readable($revisionId);
            $bytes = $pdf->bytes($revisionId);
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to download the minutes.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (\RuntimeException) {
            wp_die(esc_html__('The minutes could not be downloaded.', 'foreningsplugin'), '', ['response' => 404]);
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="protokoll-revision-' . $revision->number() . '.pdf"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }

    public static function htmlLanguage(): string
    {
        return str_replace('_', '-', determine_locale());
    }

    public static function printMinutes(): void
    {
        $revisionId = self::queryInteger('revision_id');
        check_admin_referer('assoc_print_minutes');

        try {
            $revision = WordpressMeetings::pdf()->readable($revisionId);
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to print the minutes.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (\RuntimeException) {
            wp_die(esc_html__('The minutes could not be downloaded.', 'foreningsplugin'), '', ['response' => 404]);
        }

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="' . esc_attr(self::htmlLanguage()) . '"><head><meta charset="utf-8"><title>' . esc_html(sprintf(
            /* translators: %d: revision number */
            __('Minutes, revision %d', 'foreningsplugin'),
            $revision->number()
        )) . '</title>';
        echo '<style>@page{size:A4;margin:18mm}body{font-family:Georgia,serif;font-size:12pt;line-height:1.4;white-space:pre-wrap;margin:0}</style>';
        echo '</head><body>' . esc_html($revision->body()) . '</body></html>';
        exit;
    }

    public static function uploadSignedCopy(): void
    {
        self::guardSignedUpload('assoc_upload_signed_copy');
        $meetingId = self::integer('meeting_id');

        try {
            $result = WordpressMeetings::signedCopies()->attach(
                self::integer('revision_id'),
                self::uploadedBytes('signed_copy'),
                get_current_user_id()
            );
            self::redirect($meetingId, $result === 'replaced' ? 'signed_replaced' : 'signed_uploaded');
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to upload the signed copy.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (MeetingRuleException) {
            self::redirect($meetingId, 'signed_blocked');
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect($meetingId, 'signed_type');
        }
    }

    public static function downloadSignedCopy(): void
    {
        $revisionId = self::queryInteger('revision_id');
        check_admin_referer('assoc_download_signed_copy');

        try {
            $copies = WordpressMeetings::signedCopies();
            $current = $copies->current($revisionId);
            $bytes = $copies->read($revisionId);
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to download the signed copy.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (\RuntimeException) {
            wp_die(esc_html__('The signed copy could not be downloaded.', 'foreningsplugin'), '', ['response' => 404]);
        }

        if (! $current instanceof \Foreningssystem\Domain\Meeting\SignedCopy) {
            wp_die(esc_html__('The signed copy could not be downloaded.', 'foreningsplugin'), '', ['response' => 404]);
        }

        $extension = \Foreningssystem\Domain\Meeting\SignedCopyType::extension($current->mediaType());
        nocache_headers();
        header('Content-Type: ' . $current->mediaType());
        header('Content-Disposition: attachment; filename="signerad-kopia.' . $extension . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }

    public static function render(int $meetingId): void
    {
        $meeting = self::meeting($meetingId);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($meeting instanceof Meeting ? $meeting->title() : __('Meeting', 'foreningsplugin')) . '</h1>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=foreningsplugin-meetings')) . '">' . esc_html__('All meetings', 'foreningsplugin') . '</a></p>';
        self::notice();

        if (! $meeting instanceof Meeting) {
            echo '<p>' . esc_html__('The meeting does not exist.', 'foreningsplugin') . '</p></div>';

            return;
        }

        $workspace = WordpressMeetings::workspace();
        $record = WordpressMeetings::record();
        $notes = $record->notes($meetingId);
        $decisionRows = $record->decisions($meetingId);
        $actionRows = $record->actionItems($meetingId);
        $canEdit = current_user_can(Capabilities::MANAGE_MEETINGS) || current_user_can(Capabilities::RECORD_MEETING);
        $canRecord = current_user_can(Capabilities::RECORD_MEETING);
        echo '<p>' . esc_html($meeting->startsAt()->date() . ' ' . $meeting->startsAt()->time()) . '</p>';
        echo '<p>' . esc_html__('An adjunct person does not have to be a member. The list shows names, not private email.', 'foreningsplugin') . '</p>';

        if ($canEdit) {
            echo '<h2>' . esc_html__('Add participant', 'foreningsplugin') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_add_participant">';
            echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
            wp_nonce_field('assoc_add_participant');
            echo '<p><label>' . esc_html__('Person', 'foreningsplugin') . ' <select name="person_id" required>';
            echo '<option value="">' . esc_html__('Choose person', 'foreningsplugin') . '</option>';

            foreach (WordpressPeople::service()->listPeople() as $record) {
                $person = $record->person();

                if ($person->id() === null) {
                    continue;
                }

                $name = $person->firstName() . ' ' . $person->lastName();

                if ($person->status() === PersonStatus::Deceased) {
                    $name .= ' (' . __('deceased', 'foreningsplugin') . ')';
                }

                echo '<option value="' . esc_attr((string) $person->id()) . '">' . esc_html($name) . '</option>';
            }

            echo '</select></label></p>';
            echo '<p><label>' . esc_html__('Attendance', 'foreningsplugin') . ' <select name="presence">';
            foreach (Presence::cases() as $presence) {
                echo '<option value="' . esc_attr($presence->value) . '">' . esc_html(self::presenceLabel($presence)) . '</option>';
            }
            echo '</select></label></p>';
            echo '<p><label>' . esc_html__('Duty', 'foreningsplugin') . ' <select name="meeting_duty">';
            foreach (MeetingDuty::cases() as $duty) {
                echo '<option value="' . esc_attr($duty->value) . '">' . esc_html(self::dutyLabel($duty)) . '</option>';
            }
            echo '</select></label></p>';
            submit_button(__('Add participant', 'foreningsplugin'));
            echo '</form>';
        }

        echo '<h2>' . esc_html__('Participants', 'foreningsplugin') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Name', 'foreningsplugin'), __('Attendance', 'foreningsplugin'), __('Duty', 'foreningsplugin')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        if ($canEdit) {
            echo '<th>' . esc_html__('Action', 'foreningsplugin') . '</th>';
        }
        echo '</tr></thead><tbody>';
        $attendance = $workspace->attendance($meetingId);

        if ($attendance === []) {
            echo '<tr><td colspan="4">' . esc_html__('No participants yet.', 'foreningsplugin') . '</td></tr>';
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
                ], __('Remove participant', 'foreningsplugin'));
                echo '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Notes about the meeting', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('Notes are working material, not the minutes. Mark what should be included later.', 'foreningsplugin') . '</p>';
        echo '<p>' . esc_html__('A task is something to be done, not a decision. Marking it done does not change the text.', 'foreningsplugin') . '</p>';
        self::renderCapture($meetingId, null, $notes, $decisionRows, $actionRows, $canRecord);

        if ($canEdit) {
            echo '<h2>' . esc_html__('New item', 'foreningsplugin') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_add_agenda_item">';
            echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
            wp_nonce_field('assoc_add_agenda_item');
            echo '<p><label>' . esc_html__('Item', 'foreningsplugin') . ' <input class="regular-text" type="text" name="title" required></label></p>';
            echo '<p><label>' . esc_html__('Number', 'foreningsplugin') . ' <input class="regular-text" type="text" name="number_override"></label></p>';
            submit_button(__('Add item', 'foreningsplugin'));
            echo '</form>';
        }

        echo '<h2>' . esc_html__('Agenda', 'foreningsplugin') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('No.', 'foreningsplugin'), __('Item', 'foreningsplugin')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        if ($canEdit) {
            echo '<th>' . esc_html__('Action', 'foreningsplugin') . '</th>';
        }
        echo '</tr></thead><tbody>';
        $agenda = $workspace->agenda($meetingId);

        if ($agenda === []) {
            echo '<tr><td colspan="3">' . esc_html__('No agenda yet.', 'foreningsplugin') . '</td></tr>';
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
                ], __('Up', 'foreningsplugin'));
                self::postForm('assoc_move_agenda_item', $meetingId, [
                    'item_id' => (string) $item->id(),
                    'direction' => '1',
                ], __('Down', 'foreningsplugin'));
                self::postForm('assoc_remove_agenda_item', $meetingId, [
                    'item_id' => (string) $item->id(),
                ], __('Remove item', 'foreningsplugin'));
                echo '</td>';
            }

            echo '</tr><tr><td colspan="3">';
            self::renderCapture($meetingId, $item->id(), $notes, $decisionRows, $actionRows, $canRecord);
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        self::renderMinutes($meeting, $canRecord, current_user_can(Capabilities::FINALIZE_MINUTES));
        echo '</div>';
    }

    private static function renderMinutes(Meeting $meeting, bool $canRecord, bool $canFinalize): void
    {
        $meetingId = (int) $meeting->id();
        echo '<h2>' . esc_html__('Minutes', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('The draft is a copy of the meeting, attendance, agenda, marked notes, decisions, and tasks. Later changes to those rows do not rewrite the copy.', 'foreningsplugin') . '</p>';

        if ($meeting->status() !== MeetingStatus::Held) {
            echo '<p>' . esc_html__('The draft is created when the meeting is held.', 'foreningsplugin') . '</p>';

            return;
        }

        $drafts = WordpressMeetings::minutes();
        $draft = $drafts->current($meetingId);

        if (! $draft instanceof \Foreningssystem\Domain\Meeting\MinutesRevision) {
            if (! $canRecord) {
                echo '<p>' . esc_html__('No draft yet.', 'foreningsplugin') . '</p>';

                return;
            }

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_create_minutes_draft">';
            echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
            wp_nonce_field('assoc_create_minutes_draft');
            submit_button(__('Create minutes draft', 'foreningsplugin'), 'secondary');
            echo '</form>';

            return;
        }

        $editable = $draft->state() === \Foreningssystem\Domain\Meeting\RevisionState::Draft
            || $draft->state() === \Foreningssystem\Domain\Meeting\RevisionState::UnderAdjustment;
        echo '<p>' . esc_html(self::revisionLabel($draft->state())) . '</p>';

        if ($draft->correctsRevisionId() !== null) {
            echo '<p>' . esc_html__('This is a correction of a locked revision.', 'foreningsplugin') . '</p>';
        }

        if ($draft->state() === \Foreningssystem\Domain\Meeting\RevisionState::Finalized) {
            echo '<p>' . esc_html__('The revision is locked. The text does not change if the meeting rows change.', 'foreningsplugin') . '</p>';
            self::renderPublication($meetingId, $draft);
        }

        if ($drafts->isStale($meetingId)) {
            echo '<p>' . esc_html__('The meeting details changed after the draft. Recreate the draft if the new text should be included.', 'foreningsplugin') . '</p>';
        }

        if ($editable && $draft->handEdited()) {
            echo '<p>' . esc_html__('The text has been edited by hand. The source copy remains until the draft is recreated.', 'foreningsplugin') . '</p>';
        }

        if (! $editable || ! $canRecord) {
            echo '<pre>' . esc_html($draft->body()) . '</pre>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_replace_minutes_body">';
            echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
            echo '<input type="hidden" name="revision_id" value="' . esc_attr((string) $draft->id()) . '">';
            wp_nonce_field('assoc_replace_minutes_body');
            echo '<p><label>' . esc_html__('Text', 'foreningsplugin') . '<br><textarea class="large-text" name="body" rows="16" required>' . esc_textarea($draft->body()) . '</textarea></label></p>';
            submit_button(__('Save the draft text', 'foreningsplugin'), 'secondary');
            echo '</form>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_regenerate_minutes_draft">';
            echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
            echo '<input type="hidden" name="revision_id" value="' . esc_attr((string) $draft->id()) . '">';
            wp_nonce_field('assoc_regenerate_minutes_draft');

            if ($draft->handEdited()) {
                echo '<p><label><input type="checkbox" name="confirmed" value="1"> ' . esc_html__('Replace the edited text', 'foreningsplugin') . '</label></p>';
            }

            submit_button(__('Recreate from the meeting details', 'foreningsplugin'), 'secondary');
            echo '</form>';
        }

        if ($draft->state() === \Foreningssystem\Domain\Meeting\RevisionState::Draft && $canRecord) {
            self::postForm('assoc_submit_minutes', $meetingId, [
                'revision_id' => (string) $draft->id(),
            ], __('Submit for adjustment', 'foreningsplugin'));
        }

        if ($draft->state() === \Foreningssystem\Domain\Meeting\RevisionState::UnderAdjustment && $canRecord) {
            self::postForm('assoc_send_minutes_back', $meetingId, [
                'revision_id' => (string) $draft->id(),
            ], __('Send back', 'foreningsplugin'));
        }

        if ($draft->state() === \Foreningssystem\Domain\Meeting\RevisionState::UnderAdjustment && $canFinalize) {
            self::postForm('assoc_finalize_minutes', $meetingId, [
                'revision_id' => (string) $draft->id(),
            ], __('Lock the revision', 'foreningsplugin'));
        }

        if ($draft->state() === \Foreningssystem\Domain\Meeting\RevisionState::Finalized && $canFinalize) {
            self::postForm('assoc_open_minutes_correction', $meetingId, [], __('Create correction', 'foreningsplugin'));
        }

        if ($draft->state() === \Foreningssystem\Domain\Meeting\RevisionState::Finalized || $canRecord) {
            self::minutesFileLinks($meetingId, (int) $draft->id());
        }

        self::renderSignedCopy($meetingId, $draft, $canFinalize && current_user_can(Capabilities::MANAGE_DOCUMENTS));
    }

    private static function renderPublication(int $meetingId, \Foreningssystem\Domain\Meeting\MinutesRevision $draft): void
    {
        if ($draft->supersededBy() !== null || $draft->id() === null) {
            return;
        }

        $public = $draft->visibility() === \Foreningssystem\Domain\Meeting\PublicationVisibility::Public;
        echo '<h3>' . esc_html__('Publication', 'foreningsplugin') . '</h3>';
        echo '<p>' . esc_html($public
            ? __('The revision is shown on the site. The text is unchanged, and the signed scan stays internal.', 'foreningsplugin')
            : __('The revision is not published. Publishing shows the locked text and leaves the signed scan internal.', 'foreningsplugin')
        ) . '</p>';

        if (! current_user_can(Capabilities::PUBLISH_MINUTES)) {
            return;
        }

        self::postForm(
            $public ? 'assoc_unpublish_minutes' : 'assoc_publish_minutes',
            $meetingId,
            ['revision_id' => (string) $draft->id()],
            $public ? __('Unpublish', 'foreningsplugin') : __('Publish', 'foreningsplugin')
        );
    }

    private static function renderSignedCopy(int $meetingId, \Foreningssystem\Domain\Meeting\MinutesRevision $draft, bool $canUpload): void
    {
        if ($draft->state() !== \Foreningssystem\Domain\Meeting\RevisionState::Finalized || $draft->id() === null) {
            return;
        }

        echo '<h3>' . esc_html__('Signed scan', 'foreningsplugin') . '</h3>';
        echo '<p>' . esc_html__('The signed scan is the original. The minutes text does not change when the scan is uploaded.', 'foreningsplugin') . '</p>';
        $current = WordpressMeetings::signedCopies()->current($draft->id());

        if ($current instanceof \Foreningssystem\Domain\Meeting\SignedCopy) {
            $download = wp_nonce_url(add_query_arg([
                'action' => 'assoc_download_signed_copy',
                'meeting_id' => (string) $meetingId,
                'revision_id' => (string) $draft->id(),
            ], admin_url('admin-post.php')), 'assoc_download_signed_copy');
            echo '<p><a class="button" href="' . esc_url($download) . '">' . esc_html__('Download signed copy', 'foreningsplugin') . '</a></p>';
        } else {
            echo '<p>' . esc_html__('No signed copy yet.', 'foreningsplugin') . '</p>';
        }

        if (! $canUpload) {
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="assoc_upload_signed_copy">';
        echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
        echo '<input type="hidden" name="revision_id" value="' . esc_attr((string) $draft->id()) . '">';
        wp_nonce_field('assoc_upload_signed_copy');
        echo '<p><label>' . esc_html__('PDF, JPEG, or PNG', 'foreningsplugin') . ' <input type="file" name="signed_copy" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></label></p>';
        submit_button(__('Upload signed copy', 'foreningsplugin'), 'secondary');
        echo '</form>';
    }

    private static function minutesFileLinks(int $meetingId, int $revisionId): void
    {
        $download = wp_nonce_url(add_query_arg([
            'action' => 'assoc_download_minutes_pdf',
            'meeting_id' => (string) $meetingId,
            'revision_id' => (string) $revisionId,
        ], admin_url('admin-post.php')), 'assoc_download_minutes_pdf');
        $print = wp_nonce_url(add_query_arg([
            'action' => 'assoc_print_minutes',
            'meeting_id' => (string) $meetingId,
            'revision_id' => (string) $revisionId,
        ], admin_url('admin-post.php')), 'assoc_print_minutes');
        echo '<p><a class="button" href="' . esc_url($download) . '">' . esc_html__('Download PDF', 'foreningsplugin') . '</a> ';
        echo '<a class="button" href="' . esc_url($print) . '" target="_blank" rel="noopener">' . esc_html__('Print view', 'foreningsplugin') . '</a></p>';
        echo '<p>' . esc_html__('The PDF is downloaded after a permission check and is not shown as a public link.', 'foreningsplugin') . '</p>';
    }

    private static function revisionLabel(\Foreningssystem\Domain\Meeting\RevisionState $state): string
    {
        return match ($state) {
            \Foreningssystem\Domain\Meeting\RevisionState::Draft => __('Draft', 'foreningsplugin'),
            \Foreningssystem\Domain\Meeting\RevisionState::UnderAdjustment => __('Under adjustment', 'foreningsplugin'),
            \Foreningssystem\Domain\Meeting\RevisionState::Finalized => __('Locked', 'foreningsplugin'),
        };
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
            wp_die(esc_html__('You do not have permission to change the meeting.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    private static function guardPublish(string $nonce): void
    {
        if (! current_user_can(Capabilities::PUBLISH_MINUTES)) {
            wp_die(esc_html__('You do not have permission to publish the minutes.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    private static function guardFinalize(string $nonce): void
    {
        if (! current_user_can(Capabilities::FINALIZE_MINUTES)) {
            wp_die(esc_html__('You do not have permission to lock the minutes.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    private static function guardSignedUpload(string $nonce): void
    {
        if (! current_user_can(Capabilities::FINALIZE_MINUTES) || ! current_user_can(Capabilities::MANAGE_DOCUMENTS)) {
            wp_die(esc_html__('You do not have permission to upload the signed copy.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    private static function uploadedBytes(string $key): string
    {
        $file = $_FILES[$key] ?? null;

        if (! is_array($file) || ! isset($file['tmp_name'], $file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('The signed copy was not uploaded.');
        }

        $tmp = (string) $file['tmp_name'];

        if (! is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('The signed copy was not uploaded.');
        }

        $bytes = file_get_contents($tmp);

        if (! is_string($bytes) || $bytes === '') {
            throw new \InvalidArgumentException('The signed copy was not uploaded.');
        }

        return $bytes;
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
            'participant_added' => __('The participant is added.', 'foreningsplugin'),
            'participant_removed' => __('The participant is removed from the meeting.', 'foreningsplugin'),
            'duplicate_participant' => __('The person is already at the meeting.', 'foreningsplugin'),
            'agenda_added' => __('The item is added.', 'foreningsplugin'),
            'agenda_moved' => __('The agenda is reordered.', 'foreningsplugin'),
            'agenda_removed' => __('The item is removed and the numbers are recalculated.', 'foreningsplugin'),
            'cannot_move' => __('The item cannot be moved that way.', 'foreningsplugin'),
            'note_added' => __('The note is saved.', 'foreningsplugin'),
            'note_removed' => __('The note is removed.', 'foreningsplugin'),
            'decision_added' => __('The decision is saved.', 'foreningsplugin'),
            'follow_up' => __('The follow-up is changed. The wording of the decision is the same.', 'foreningsplugin'),
            'decision_removed' => __('The decision is removed.', 'foreningsplugin'),
            'action_added' => __('The task is saved.', 'foreningsplugin'),
            'action_status' => __('The task status is changed. The text is the same.', 'foreningsplugin'),
            'action_removed' => __('The task is removed.', 'foreningsplugin'),
            'draft_created' => __('The minutes draft is created from the meeting details.', 'foreningsplugin'),
            'draft_saved' => __('The draft text is saved. The source details are unchanged.', 'foreningsplugin'),
            'draft_regenerated' => __('The draft is recreated from the meeting details.', 'foreningsplugin'),
            'draft_blocked' => __('The draft is created when the meeting is held, and only once.', 'foreningsplugin'),
            'confirm_regenerate' => __('Confirm whether the edited text should be replaced.', 'foreningsplugin'),
            'minutes_submitted' => __('The draft has been submitted for adjustment.', 'foreningsplugin'),
            'minutes_returned' => __('The revision is back as a draft.', 'foreningsplugin'),
            'minutes_finalized' => __('The revision is locked. The text no longer changes.', 'foreningsplugin'),
            'minutes_correction' => __('The correction is a new draft with the locked text.', 'foreningsplugin'),
            'minutes_published' => __('The revision is shown on the site. The text is unchanged.', 'foreningsplugin'),
            'minutes_unpublished' => __('The revision is unpublished. The text is unchanged.', 'foreningsplugin'),
            'minutes_blocked' => __('This change does not fit the revision\'s state.', 'foreningsplugin'),
            'signed_uploaded' => __('The signed copy is saved. The minutes text is unchanged.', 'foreningsplugin'),
            'signed_replaced' => __('The signed copy is replaced. The minutes text is unchanged.', 'foreningsplugin'),
            'signed_blocked' => __('A signed copy can be attached only to a locked revision.', 'foreningsplugin'),
            'signed_type' => __('The signed copy must be a PDF, JPEG, or PNG.', 'foreningsplugin'),
            'wrong_item' => __('The item does not belong to this meeting.', 'foreningsplugin'),
            'invalid' => __('Check the details and try again.', 'foreningsplugin'),
        ];

        if (! isset($messages[$notice])) {
            return;
        }

        $class = in_array($notice, ['participant_added', 'participant_removed', 'agenda_added', 'agenda_moved', 'agenda_removed', 'note_added', 'note_removed', 'decision_added', 'follow_up', 'decision_removed', 'action_added', 'action_status', 'action_removed', 'draft_created', 'draft_saved', 'draft_regenerated', 'minutes_submitted', 'minutes_returned', 'minutes_finalized', 'minutes_correction', 'minutes_published', 'minutes_unpublished', 'signed_uploaded', 'signed_replaced'], true)
            ? 'notice-success'
            : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }

    private static function presenceLabel(Presence $presence): string
    {
        return match ($presence) {
            Presence::Present => __('Present', 'foreningsplugin'),
            Presence::Absent => __('Absent', 'foreningsplugin'),
            Presence::CoOpted => __('Adjunct', 'foreningsplugin'),
        };
    }

    private static function dutyLabel(MeetingDuty $duty): string
    {
        return match ($duty) {
            MeetingDuty::None => __('None', 'foreningsplugin'),
            MeetingDuty::Chair => __('Chair', 'foreningsplugin'),
            MeetingDuty::Adjuster => __('Adjuster', 'foreningsplugin'),
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

    private static function queryInteger(string $key): int
    {
        $value = $_GET[$key] ?? 0;

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
            wp_die(esc_html__('You do not have permission to record notes, decisions, or tasks.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    /**
     * @param list<\Foreningssystem\Domain\Meeting\MeetingNote> $notes
     * @param list<\Foreningssystem\Application\Meeting\DecisionRow> $decisionRows
     * @param list<\Foreningssystem\Application\Meeting\ActionItemRow> $actionRows
     */
    private static function renderCapture(int $meetingId, ?int $agendaItemId, array $notes, array $decisionRows, array $actionRows, bool $canRecord): void
    {
        foreach ($notes as $note) {
            if ($note->agendaItemId() !== $agendaItemId) {
                continue;
            }

            echo '<p><strong>' . esc_html__('Note', 'foreningsplugin') . '</strong> ';
            echo esc_html($note->includeInMinutes() ? __('Included in the minutes', 'foreningsplugin') : __('Working note', 'foreningsplugin'));
            echo '<br>' . esc_html($note->body()) . '</p>';

            if ($canRecord) {
                self::postForm('assoc_remove_note', $meetingId, [
                    'note_id' => (string) $note->id(),
                ], __('Remove note', 'foreningsplugin'));
            }
        }

        foreach ($decisionRows as $row) {
            $decision = $row->decision();

            if ($decision->agendaItemId() !== $agendaItemId) {
                continue;
            }

            $meta = $decision->followUp() === DecisionFollowUp::Done
                ? __('Done', 'foreningsplugin')
                : __('Open', 'foreningsplugin');

            if ($row->responsibleName() !== null) {
                $meta .= ', ' . $row->responsibleName();
            }

            if ($decision->deadline() !== null) {
                $meta .= ', ' . $decision->deadline()->iso();
            }

            echo '<p><strong>' . esc_html__('Decision', 'foreningsplugin') . '</strong> ' . esc_html($meta);
            echo '<br>' . esc_html($decision->wording()) . '</p>';

            if ($canRecord) {
                $next = $decision->followUp() === DecisionFollowUp::Open ? DecisionFollowUp::Done : DecisionFollowUp::Open;
                $label = $next === DecisionFollowUp::Done
                    ? __('Mark as done', 'foreningsplugin')
                    : __('Reopen', 'foreningsplugin');
                self::postForm('assoc_set_decision_follow_up', $meetingId, [
                    'decision_id' => (string) $decision->id(),
                    'follow_up' => $next->value,
                ], $label);
                self::postForm('assoc_remove_decision', $meetingId, [
                    'decision_id' => (string) $decision->id(),
                ], __('Remove decision', 'foreningsplugin'));
            }
        }

        foreach ($actionRows as $actionRow) {
            $action = $actionRow->item();

            if ($action->agendaItemId() !== $agendaItemId) {
                continue;
            }

            $meta = $action->status() === ActionStatus::Done
                ? __('Done', 'foreningsplugin')
                : __('Open', 'foreningsplugin');

            if ($actionRow->assigneeName() !== null) {
                $meta .= ', ' . $actionRow->assigneeName();
            }

            if ($action->dueOn() !== null) {
                $meta .= ', ' . $action->dueOn()->iso();
            }

            echo '<p><strong>' . esc_html__('Task', 'foreningsplugin') . '</strong> ' . esc_html($meta);
            echo '<br>' . esc_html($action->task()) . '</p>';

            if ($canRecord) {
                $next = $action->status() === ActionStatus::Open ? ActionStatus::Done : ActionStatus::Open;
                $label = $next === ActionStatus::Done
                    ? __('Mark as done', 'foreningsplugin')
                    : __('Reopen', 'foreningsplugin');
                self::postForm('assoc_set_action_status', $meetingId, [
                    'action_item_id' => (string) $action->id(),
                    'status' => $next->value,
                ], $label);
                self::postForm('assoc_remove_action_item', $meetingId, [
                    'action_item_id' => (string) $action->id(),
                ], __('Remove task', 'foreningsplugin'));
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
        echo '<p><label>' . esc_html__('Note', 'foreningsplugin') . '<br><textarea class="large-text" name="body" rows="3" required></textarea></label></p>';
        echo '<p><label><input type="checkbox" name="include_in_minutes" value="1"> ' . esc_html__('Include in the minutes', 'foreningsplugin') . '</label></p>';
        submit_button(__('Save note', 'foreningsplugin'), 'secondary');
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_add_decision">';
        echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
        echo $itemField;
        wp_nonce_field('assoc_add_decision');
        echo '<p><label>' . esc_html__('Decision', 'foreningsplugin') . '<br><textarea class="large-text" name="wording" rows="3" required></textarea></label></p>';
        echo '<p><label>' . esc_html__('Responsible', 'foreningsplugin') . ' <select name="responsible_person_id">';
        echo '<option value="">' . esc_html__('None', 'foreningsplugin') . '</option>';

        foreach (WordpressPeople::service()->listPeople() as $personRecord) {
            $person = $personRecord->person();

            if ($person->id() === null) {
                continue;
            }

            echo '<option value="' . esc_attr((string) $person->id()) . '">' . esc_html($person->firstName() . ' ' . $person->lastName()) . '</option>';
        }

        echo '</select></label></p>';
        echo '<p><label>' . esc_html__('Deadline', 'foreningsplugin') . ' <input type="date" name="deadline"></label></p>';
        submit_button(__('Save decision', 'foreningsplugin'), 'secondary');
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_add_action_item">';
        echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
        echo $itemField;
        wp_nonce_field('assoc_add_action_item');
        echo '<p><label>' . esc_html__('Task', 'foreningsplugin') . '<br><textarea class="large-text" name="task" rows="3" required></textarea></label></p>';
        echo '<p><label>' . esc_html__('Responsible', 'foreningsplugin') . ' <select name="assignee_person_id">';
        echo '<option value="">' . esc_html__('None', 'foreningsplugin') . '</option>';

        foreach (WordpressPeople::service()->listPeople() as $personRecord) {
            $person = $personRecord->person();

            if ($person->id() === null) {
                continue;
            }

            echo '<option value="' . esc_attr((string) $person->id()) . '">' . esc_html($person->firstName() . ' ' . $person->lastName()) . '</option>';
        }

        echo '</select></label></p>';
        echo '<p><label>' . esc_html__('Deadline', 'foreningsplugin') . ' <input type="date" name="due_on"></label></p>';
        submit_button(__('Save task', 'foreningsplugin'), 'secondary');
        echo '</form>';
    }
}
