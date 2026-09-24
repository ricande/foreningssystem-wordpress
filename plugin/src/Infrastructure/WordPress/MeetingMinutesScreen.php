<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\PublicationVisibility;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Domain\Meeting\SignedCopy;

final class MeetingMinutesScreen
{
    public static function render(Meeting $meeting, bool $canRecord, bool $canFinalize, ?MinutesRevision $current): void
    {
        $meetingId = (int) $meeting->id();
        echo '<h2>' . esc_html__('Minutes', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('A held meeting is not finalized minutes. The draft is a copy of the meeting, attendance, agenda, notes marked for inclusion, decisions, and tasks.', 'foreningsplugin') . '</p>';

        if ($meeting->status() !== MeetingStatus::Held) {
            echo '<p>' . esc_html__('Create the minutes draft after the meeting is marked as held.', 'foreningsplugin') . '</p>';

            return;
        }

        $drafts = WordpressMeetings::minutes();

        if (! $current instanceof MinutesRevision) {
            if (! $canRecord) {
                echo '<p>' . esc_html__('No draft yet.', 'foreningsplugin') . '</p>';

                return;
            }

            MeetingAdminForms::begin('assoc_create_minutes_draft', $meetingId);
            echo '<p>' . esc_html__('The draft is composed from the meeting, attendance, agenda, notes marked for inclusion, decisions, and tasks.', 'foreningsplugin') . '</p>';
            submit_button(__('Create minutes draft', 'foreningsplugin'), 'primary');
            echo '</form>';

            return;
        }

        $numbers = [];

        foreach ($drafts->history($meetingId) as $revision) {
            if ($revision->id() !== null) {
                $numbers[$revision->id()] = $revision->number();
            }
        }

        foreach ($drafts->history($meetingId) as $revision) {
            self::revisionSummary($revision, $current, $numbers);
        }

        $editable = $current->state() === RevisionState::Draft || $current->state() === RevisionState::UnderAdjustment;
        $heading = $current->state() === RevisionState::Finalized
            ? sprintf(
                /* translators: %d: revision number */
                __('Revision %d', 'foreningsplugin'),
                $current->number()
            ) . ' · ' . MeetingLabels::revision($current->state())
            : sprintf(
                /* translators: %d: revision number */
                __('Minutes draft — revision %d', 'foreningsplugin'),
                $current->number()
            );
        echo '<h3>' . esc_html($heading) . '</h3>';

        if ($current->correctsRevisionId() !== null) {
            echo '<p>' . esc_html(sprintf(
                /* translators: %d: earlier revision number */
                __('Correction of revision %d', 'foreningsplugin'),
                $numbers[$current->correctsRevisionId()] ?? $current->correctsRevisionId()
            )) . '</p>';
        }

        if ($current->state() === RevisionState::Finalized) {
            echo '<p>' . esc_html__('This revision is preserved. Further corrections use a new revision.', 'foreningsplugin') . '</p>';
            echo '<pre>' . esc_html($current->body()) . '</pre>';
        } elseif (! $editable || ! $canRecord) {
            echo '<pre>' . esc_html($current->body()) . '</pre>';
        } else {
            if ($drafts->isStale($meetingId)) {
                echo '<p>' . esc_html__('The meeting details changed after the draft. Regenerating replaces the draft text with a new version from the meeting data.', 'foreningsplugin') . '</p>';
            }

            if ($current->handEdited()) {
                echo '<p>' . esc_html__('This draft has been edited by hand.', 'foreningsplugin') . '</p>';
            }

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_replace_minutes_body">';
            echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
            echo '<input type="hidden" name="revision_id" value="' . esc_attr((string) $current->id()) . '">';
            wp_nonce_field('assoc_replace_minutes_body');
            echo '<p><label>' . esc_html__('Minutes text', 'foreningsplugin') . '<br><textarea class="large-text" name="body" rows="16" required>' . esc_textarea($current->body()) . '</textarea></label></p>';
            submit_button(__('Save draft', 'foreningsplugin'));
            echo '</form>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_regenerate_minutes_draft">';
            echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
            echo '<input type="hidden" name="revision_id" value="' . esc_attr((string) $current->id()) . '">';
            wp_nonce_field('assoc_regenerate_minutes_draft');

            if ($current->handEdited()) {
                echo '<p>' . esc_html__('Regenerating replaces the current draft text with a new version generated from the meeting data.', 'foreningsplugin') . '</p>';
                echo '<p>' . esc_html__('Manual edits in this draft will be lost.', 'foreningsplugin') . '</p>';
                echo '<p><label><input type="checkbox" name="confirmed" value="1" required> ' . esc_html__('Replace the edited draft', 'foreningsplugin') . '</label></p>';
            }

            submit_button(__('Regenerate from meeting data', 'foreningsplugin'), 'secondary');
            echo '</form>';
        }

        if ($current->state() === RevisionState::Draft && $canRecord) {
            MeetingAdminForms::post('assoc_submit_minutes', $meetingId, [
                'revision_id' => (string) $current->id(),
            ], __('Send for review / adjustment', 'foreningsplugin'));
        }

        if ($current->state() === RevisionState::UnderAdjustment && $canRecord) {
            MeetingAdminForms::post('assoc_send_minutes_back', $meetingId, [
                'revision_id' => (string) $current->id(),
            ], __('Return for changes', 'foreningsplugin'));
        }

        if ($current->state() === RevisionState::UnderAdjustment && $canFinalize) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_finalize_minutes">';
            echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
            echo '<input type="hidden" name="revision_id" value="' . esc_attr((string) $current->id()) . '">';
            wp_nonce_field('assoc_finalize_minutes');
            echo '<p>' . esc_html(sprintf(
                /* translators: %d: revision number */
                __('Finalize minutes revision %d? The finalized revision is preserved as a historical record. Further corrections use a new correction revision rather than silently replacing it.', 'foreningsplugin'),
                $current->number()
            )) . '</p>';
            echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__('Finalize this revision', 'foreningsplugin') . '</label></p>';
            submit_button(__('Finalize', 'foreningsplugin'), 'primary');
            echo '</form>';
        }

        if ($current->state() === RevisionState::Finalized && $canFinalize && $current->supersededBy() === null) {
            MeetingAdminForms::post('assoc_open_minutes_correction', $meetingId, [], __('Create correction revision', 'foreningsplugin'));
        }

        if ($current->state() === RevisionState::Finalized || $canRecord) {
            self::fileLinks($meetingId, (int) $current->id());
        }

        if ($current->state() === RevisionState::Finalized) {
            self::publication($meetingId, $current);
            self::signedCopy($meetingId, $current, $canFinalize && current_user_can(Capabilities::MANAGE_DOCUMENTS));
        }
    }

    /**
     * @param array<int, int> $numbers
     */
    private static function revisionSummary(MinutesRevision $revision, MinutesRevision $current, array $numbers): void
    {
        if ($revision->id() === $current->id()) {
            return;
        }

        echo '<p>';
        echo esc_html(sprintf(
            /* translators: %d: revision number */
            __('Revision %d', 'foreningsplugin'),
            $revision->number()
        ));
        echo ' · ' . esc_html(MeetingLabels::revision($revision->state()));

        if ($revision->correctsRevisionId() !== null) {
            echo ' · ' . esc_html(sprintf(
                /* translators: %d: earlier revision number */
                __('Correction of revision %d', 'foreningsplugin'),
                $numbers[$revision->correctsRevisionId()] ?? $revision->correctsRevisionId()
            ));
        }

        if ($revision->supersededBy() !== null) {
            echo ' · ' . esc_html(sprintf(
                /* translators: %d: later revision number */
                __('Superseded by revision %d', 'foreningsplugin'),
                $numbers[$revision->supersededBy()] ?? $revision->supersededBy()
            ));
        }

        echo '</p>';
    }

    private static function publication(int $meetingId, MinutesRevision $revision): void
    {
        if ($revision->supersededBy() !== null || $revision->id() === null) {
            echo '<p>' . esc_html__('This revision is not the current minutes.', 'foreningsplugin') . '</p>';

            return;
        }

        $public = $revision->visibility() === PublicationVisibility::Public;
        echo '<h3>' . esc_html__('Publication', 'foreningsplugin') . '</h3>';
        echo '<p>' . esc_html($public
            ? __('Published. The text is unchanged, and the signed copy stays private.', 'foreningsplugin')
            : __('Visibility: Board. Publishing shows the finalized text and does not publish the signed copy.', 'foreningsplugin')
        ) . '</p>';

        if (! current_user_can(Capabilities::PUBLISH_MINUTES)) {
            return;
        }

        MeetingAdminForms::post(
            $public ? 'assoc_unpublish_minutes' : 'assoc_publish_minutes',
            $meetingId,
            ['revision_id' => (string) $revision->id()],
            $public ? __('Unpublish', 'foreningsplugin') : __('Publish publicly', 'foreningsplugin')
        );
    }

    private static function signedCopy(int $meetingId, MinutesRevision $revision, bool $canUpload): void
    {
        if ($revision->id() === null) {
            return;
        }

        echo '<h3>' . esc_html__('Signed copy', 'foreningsplugin') . '</h3>';
        echo '<p>' . esc_html__('Signed copies are stored privately and are not published automatically.', 'foreningsplugin') . '</p>';
        echo '<p>' . esc_html__('The signed scan is the archival original when it differs from the finalized text. The minutes text does not change when the scan is uploaded.', 'foreningsplugin') . '</p>';
        $current = WordpressMeetings::signedCopies()->current($revision->id());

        if ($current instanceof SignedCopy) {
            $download = wp_nonce_url(add_query_arg([
                'action' => 'assoc_download_signed_copy',
                'meeting_id' => (string) $meetingId,
                'revision_id' => (string) $revision->id(),
            ], admin_url('admin-post.php')), 'assoc_download_signed_copy');
            echo '<p>' . esc_html__('A signed copy is stored.', 'foreningsplugin') . ' <a class="button" href="' . esc_url($download) . '">' . esc_html__('Download signed copy', 'foreningsplugin') . '</a></p>';
        } else {
            echo '<p>' . esc_html__('No signed copy yet.', 'foreningsplugin') . '</p>';
        }

        if (! $canUpload) {
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="assoc_upload_signed_copy">';
        echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
        echo '<input type="hidden" name="revision_id" value="' . esc_attr((string) $revision->id()) . '">';
        wp_nonce_field('assoc_upload_signed_copy');
        echo '<p><label>' . esc_html__('PDF, JPEG, or PNG', 'foreningsplugin') . ' <input type="file" name="signed_copy" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></label></p>';
        submit_button(__('Upload signed copy', 'foreningsplugin'), 'secondary');
        echo '</form>';
    }

    private static function fileLinks(int $meetingId, int $revisionId): void
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
}
