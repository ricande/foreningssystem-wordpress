<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;

global $wpdb;

$meetings = $wpdb->prefix . 'assoc_meeting';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$notes = $wpdb->prefix . 'assoc_meeting_note';
$decisions = $wpdb->prefix . 'assoc_decision';
$minutes = $wpdb->prefix . 'assoc_minutes';
$revisions = $wpdb->prefix . 'assoc_minutes_revision';

$meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-FINAL%'));

if (is_array($meetingIds)) {
    foreach ($meetingIds as $meetingId) {
        $wpdb->delete($revisions, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($minutes, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($decisions, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($notes, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($agenda, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($meetings, ['id' => (int) $meetingId], ['%d']);
    }
}

$secretary = get_user_by('login', 'lab-secretary');
$chair = get_user_by('login', 'lab-chair');
$boardMember = get_user_by('login', 'lab-board-member');

if (! $secretary instanceof WP_User || ! $chair instanceof WP_User || ! $boardMember instanceof WP_User) {
    \WP_CLI::error('Lab users are missing.');
}

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$meetingService = WordpressMeetings::service();
$workspace = WordpressMeetings::workspace();
$record = WordpressMeetings::record();
$drafts = WordpressMeetings::minutes();
$typeId = null;

foreach ($meetingService->types() as $type) {
    if ($type->slug() === 'board_meeting') {
        $typeId = $type->id();
    }
}

if ($typeId === null) {
    \WP_CLI::error('Board meeting type was not seeded.');
}

$meetingId = $meetingService->schedule($typeId, 'LAB-FINAL styrelse', MeetingMoment::fromLocal('2024-05-02 18:00'), 'Lokalen');
$itemId = $workspace->addAgendaItem($meetingId, 'Inköp', '');
$record->addNote($meetingId, $itemId, 'Tre offerter granskades.', true);
$decisionId = $record->addDecision($meetingId, $itemId, 'Föreningen köper modell X.', null, null);
$meetingService->markHeld($meetingId);
$draftId = $drafts->create($meetingId);

$tooSoon = false;

try {
    $drafts->finalize($draftId);
} catch (NotAllowed) {
    $tooSoon = true;
}

if (! $tooSoon) {
    \WP_CLI::error('A secretary could finalize a draft.');
}

$drafts->submit($draftId);

$denied = false;

try {
    $drafts->finalize($draftId);
} catch (NotAllowed) {
    $denied = true;
}

if (! $denied || $drafts->current($meetingId)?->state() !== RevisionState::UnderAdjustment) {
    \WP_CLI::error('A secretary could lock the minutes.');
}

clean_user_cache($chair->ID);
wp_set_current_user($chair->ID);
$drafts->finalize($draftId);
$locked = $drafts->current($meetingId);

if ($locked === null || $locked->state() !== RevisionState::Finalized || $locked->supersededBy() !== null) {
    \WP_CLI::error('The chair did not lock the revision.');
}

$lockedBody = $locked->body();
clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$record->reviseDecision($decisionId, 'Föreningen köper modell Y.', null, null);
$unchanged = $drafts->current($meetingId);

if ($unchanged === null || $unchanged->body() !== $lockedBody || $drafts->isStale($meetingId)) {
    \WP_CLI::error('A locked revision changed with the live decision.');
}

$edited = false;

try {
    $drafts->replaceBody($draftId, 'Tyst ändring.');
} catch (MeetingRuleException) {
    $edited = true;
}

if (! $edited || $drafts->revision($draftId)->body() !== $lockedBody) {
    \WP_CLI::error('A locked revision accepted a new body.');
}

clean_user_cache($chair->ID);
wp_set_current_user($chair->ID);
$correctionId = $drafts->openCorrection($meetingId);
$correction = $drafts->current($meetingId);

if (
    $correction === null
    || $correction->id() !== $correctionId
    || $correction->number() !== 2
    || $correction->state() !== RevisionState::Draft
    || $correction->correctsRevisionId() !== $draftId
    || $correction->body() !== $lockedBody
    || ! str_contains($correction->body(), 'Föreningen köper modell X.')
    || $drafts->revision($draftId)->supersededBy() !== null
) {
    \WP_CLI::error('The correction did not copy the locked text.');
}

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$drafts->submit($correctionId);
clean_user_cache($chair->ID);
wp_set_current_user($chair->ID);
$drafts->finalize($correctionId);
$previous = $drafts->revision($draftId);
$latest = $drafts->current($meetingId);

if (
    $previous->body() !== $lockedBody
    || $previous->supersededBy() !== $correctionId
    || $latest === null
    || $latest->id() !== $correctionId
    || $latest->state() !== RevisionState::Finalized
    || $latest->body() !== $lockedBody
    || $latest->supersededBy() !== null
) {
    \WP_CLI::error('Finalizing the correction did not keep both revisions.');
}

clean_user_cache($boardMember->ID);
wp_set_current_user($boardMember->ID);
$visible = $drafts->current($meetingId);
$blocked = false;

try {
    $drafts->submit($correctionId);
} catch (NotAllowed) {
    $blocked = true;
}

if ($visible === null || ! str_contains($visible->body(), 'Föreningen köper modell X.') || ! $blocked) {
    \WP_CLI::error('A board member could change the locked minutes.');
}

wp_set_current_user(1);
$wpdb->delete($revisions, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($minutes, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($decisions, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($notes, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($agenda, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($meetings, ['id' => $meetingId], ['%d']);

\WP_CLI::success('A finalized revision stays put, and a correction is a new revision.');
