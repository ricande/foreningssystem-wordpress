<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Membership\AssociationDate;

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
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'duplicate_participant'));
        } catch (\InvalidArgumentException | \RuntimeException | \ValueError) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function removeParticipant(): void
    {
        self::guard('assoc_remove_participant');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::workspace()->removeParticipant(self::integer('participant_id'), $meetingId);
            self::redirect($meetingId, 'participant_removed');
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'invalid'));
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
            WordpressMeetings::workspace()->moveAgendaItem(self::integer('item_id'), self::integer('direction'), $meetingId);
            self::redirect($meetingId, 'agenda_moved', self::integer('item_id'));
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'cannot_move'));
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function removeAgendaItem(): void
    {
        self::guard('assoc_remove_agenda_item');
        $meetingId = self::integer('meeting_id');

        try {
            if (! self::confirmed()) {
                self::redirect($meetingId, 'confirm_remove');
            }

            WordpressMeetings::workspace()->removeAgendaItem(self::integer('item_id'), $meetingId);
            self::redirect($meetingId, 'agenda_removed');
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'invalid'));
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
            self::redirect($meetingId, 'note_added', self::optionalInteger('agenda_item_id'));
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'wrong_item'), self::optionalInteger('agenda_item_id'));
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function removeNote(): void
    {
        self::guardRecord('assoc_remove_note');
        $meetingId = self::integer('meeting_id');

        try {
            if (! self::confirmed()) {
                self::redirect($meetingId, 'confirm_remove', self::optionalInteger('agenda_item_id'));
            }

            WordpressMeetings::record()->removeNote(self::integer('note_id'), $meetingId);
            self::redirect($meetingId, 'note_removed', self::optionalInteger('agenda_item_id'));
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'invalid'), self::optionalInteger('agenda_item_id'));
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
            self::redirect($meetingId, 'decision_added', self::optionalInteger('agenda_item_id'));
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'wrong_item'), self::optionalInteger('agenda_item_id'));
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
                DecisionFollowUp::from(self::text('follow_up')),
                $meetingId
            );
            self::redirect($meetingId, 'follow_up', self::optionalInteger('agenda_item_id'));
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'invalid'), self::optionalInteger('agenda_item_id'));
        } catch (\ValueError | \RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function removeDecision(): void
    {
        self::guardRecord('assoc_remove_decision');
        $meetingId = self::integer('meeting_id');

        try {
            if (! self::confirmed()) {
                self::redirect($meetingId, 'confirm_remove', self::optionalInteger('agenda_item_id'));
            }

            WordpressMeetings::record()->removeDecision(self::integer('decision_id'), $meetingId);
            self::redirect($meetingId, 'decision_removed', self::optionalInteger('agenda_item_id'));
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'invalid'), self::optionalInteger('agenda_item_id'));
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
            self::redirect($meetingId, 'action_added', self::optionalInteger('agenda_item_id'));
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'wrong_item'), self::optionalInteger('agenda_item_id'));
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
                ActionStatus::from(self::text('status')),
                $meetingId
            );
            self::redirect($meetingId, 'action_status', self::optionalInteger('agenda_item_id'));
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'invalid'), self::optionalInteger('agenda_item_id'));
        } catch (\ValueError | \RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function removeActionItem(): void
    {
        self::guardRecord('assoc_remove_action_item');
        $meetingId = self::integer('meeting_id');

        try {
            if (! self::confirmed()) {
                self::redirect($meetingId, 'confirm_remove', self::optionalInteger('agenda_item_id'));
            }

            WordpressMeetings::record()->removeActionItem(self::integer('action_item_id'), $meetingId);
            self::redirect($meetingId, 'action_removed', self::optionalInteger('agenda_item_id'));
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'invalid'), self::optionalInteger('agenda_item_id'));
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
            WordpressMeetings::minutes()->replaceBody(self::integer('revision_id'), self::textarea('body'), $meetingId);
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
            WordpressMeetings::minutes()->regenerate(self::integer('revision_id'), self::text('confirmed') === '1', $meetingId);
            self::redirect($meetingId, 'draft_regenerated');
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'confirm_regenerate'));
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function submitMinutes(): void
    {
        self::guardRecord('assoc_submit_minutes');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::minutes()->submit(self::integer('revision_id'), $meetingId);
            self::redirect($meetingId, 'minutes_submitted');
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'minutes_blocked'));
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function sendMinutesBack(): void
    {
        self::guardRecord('assoc_send_minutes_back');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::minutes()->sendBack(self::integer('revision_id'), $meetingId);
            self::redirect($meetingId, 'minutes_returned');
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'minutes_blocked'));
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function finalizeMinutes(): void
    {
        self::guardFinalize('assoc_finalize_minutes');
        $meetingId = self::integer('meeting_id');

        try {
            if (! self::confirmed()) {
                self::redirect($meetingId, 'confirm_finalize');
            }

            WordpressMeetings::minutes()->finalize(self::integer('revision_id'), $meetingId);
            self::redirect($meetingId, 'minutes_finalized');
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'minutes_blocked'));
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function publishMinutes(): void
    {
        self::guardPublish('assoc_publish_minutes');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::publication()->publish(self::integer('revision_id'), $meetingId);
            self::redirect($meetingId, 'minutes_published');
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'minutes_blocked'));
        } catch (\RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    public static function unpublishMinutes(): void
    {
        self::guardPublish('assoc_unpublish_minutes');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::publication()->unpublish(self::integer('revision_id'), $meetingId);
            self::redirect($meetingId, 'minutes_unpublished');
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'minutes_blocked'));
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
            $revision = $pdf->readable($revisionId, self::queryInteger('meeting_id') > 0 ? self::queryInteger('meeting_id') : null);
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
            $revision = WordpressMeetings::pdf()->readable($revisionId, self::queryInteger('meeting_id') > 0 ? self::queryInteger('meeting_id') : null);
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
                get_current_user_id(),
                $meetingId
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
            $scopedMeeting = self::queryInteger('meeting_id');
            $current = $copies->current($revisionId);
            $bytes = $copies->read($revisionId);

            if ($scopedMeeting > 0) {
                $revision = WordpressMeetings::pdf()->readable($revisionId, $scopedMeeting);
                unset($revision);
            }
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
        MeetingWorkspaceScreen::render($meetingId);
    }

    public static function saveHeader(): void
    {
        self::guard('assoc_save_meeting_header');
        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::service()->updateHeader(
                $meetingId,
                self::integer('type_id'),
                self::text('title'),
                \Foreningssystem\Domain\Meeting\MeetingMoment::fromLocal(self::text('meeting_date') . ' ' . self::text('meeting_time')),
                self::text('place')
            );
            self::redirect($meetingId, 'header_saved');
        } catch (MeetingRuleException $error) {
            self::redirect($meetingId, self::ruleNotice($error, 'invalid'));
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect($meetingId, 'invalid');
        }
    }

    private static function redirect(int $meetingId, string $notice, ?int $agendaItemId = null): void
    {
        $args = [
            'page' => 'foreningsplugin-meetings',
            'meeting' => $meetingId,
            'assoc_notice' => $notice,
        ];

        if ($agendaItemId !== null && $agendaItemId > 0) {
            $args['agenda'] = $agendaItemId;
        }

        $url = add_query_arg($args, admin_url('admin.php'));

        if ($agendaItemId !== null && $agendaItemId > 0) {
            $url .= '#agenda-' . $agendaItemId;
        }

        wp_safe_redirect($url);
        exit;
    }

    private static function confirmed(): bool
    {
        return self::text('confirm') === '1';
    }

    private static function ruleNotice(MeetingRuleException $error, string $fallback): string
    {
        return match ($error->getMessage()) {
            'A deceased person cannot be added to a meeting.' => 'deceased',
            'The record does not belong to this meeting.' => 'wrong_meeting',
            'Remove the notes, decisions, and tasks on this item before removing it.' => 'agenda_linked',
            'The agenda item does not belong to this meeting.' => 'wrong_item',
            'Confirm before replacing a hand-edited draft.' => 'confirm_regenerate',
            default => $fallback,
        };
    }

    public static function notice(): void
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
            'header_saved' => __('The meeting details are saved.', 'foreningsplugin'),
            'deceased' => __('A deceased person cannot be added to a meeting.', 'foreningsplugin'),
            'wrong_meeting' => __('That record belongs to another meeting.', 'foreningsplugin'),
            'agenda_linked' => __('Remove the notes, decisions, and tasks on this item before removing it.', 'foreningsplugin'),
            'confirm_remove' => __('Confirm before removing that record.', 'foreningsplugin'),
            'confirm_finalize' => __('Confirm before finalizing the revision.', 'foreningsplugin'),
            'invalid' => __('Check the details and try again.', 'foreningsplugin'),
        ];

        if (! isset($messages[$notice])) {
            return;
        }

        $class = in_array($notice, ['participant_added', 'participant_removed', 'agenda_added', 'agenda_moved', 'agenda_removed', 'note_added', 'note_removed', 'decision_added', 'follow_up', 'decision_removed', 'action_added', 'action_status', 'action_removed', 'draft_created', 'draft_saved', 'draft_regenerated', 'minutes_submitted', 'minutes_returned', 'minutes_finalized', 'minutes_correction', 'minutes_published', 'minutes_unpublished', 'signed_uploaded', 'signed_replaced', 'header_saved'], true)
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
}
