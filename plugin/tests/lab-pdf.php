<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;

global $wpdb;

$meetings = $wpdb->prefix . 'assoc_meeting';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$decisions = $wpdb->prefix . 'assoc_decision';
$minutes = $wpdb->prefix . 'assoc_minutes';
$revisions = $wpdb->prefix . 'assoc_minutes_revision';

$meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-PDF%'));

if (is_array($meetingIds)) {
    foreach ($meetingIds as $meetingId) {
        $wpdb->delete($revisions, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($minutes, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($decisions, ['meeting_id' => (int) $meetingId], ['%d']);
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
$record = WordpressMeetings::record();
$drafts = WordpressMeetings::minutes();
$pdf = WordpressMeetings::pdf();
$typeId = null;

foreach ($meetingService->types() as $type) {
    if ($type->slug() === 'board_meeting') {
        $typeId = $type->id();
    }
}

if ($typeId === null) {
    \WP_CLI::error('Board meeting type was not seeded.');
}

$meetingId = $meetingService->schedule($typeId, 'LAB-PDF styrelse', MeetingMoment::fromLocal('2024-05-02 18:00'), 'Lokalen');
$itemId = WordpressMeetings::workspace()->addAgendaItem($meetingId, 'Inköp', '');
$decisionId = $record->addDecision($meetingId, $itemId, 'Föreningen köper modell X.', null, null);
$meetingService->markHeld($meetingId);
$draftId = $drafts->create($meetingId);
$drafts->submit($draftId);
clean_user_cache($chair->ID);
wp_set_current_user($chair->ID);
$drafts->finalize($draftId);

$swedish = iconv('UTF-8', 'Windows-1252', 'Föreningen köper modell X.');
$first = $pdf->bytes($draftId);
$hash = $wpdb->get_var($wpdb->prepare("SELECT pdf_source_hash FROM {$revisions} WHERE id = %d", $draftId));
$second = $pdf->bytes($draftId);
$hashAgain = $wpdb->get_var($wpdb->prepare("SELECT pdf_source_hash FROM {$revisions} WHERE id = %d", $draftId));

if (! is_string($swedish) || ! str_starts_with($first, '%PDF') || ! str_contains($first, $swedish) || $first !== $second || $hash === null || $hash !== $hashAgain) {
    \WP_CLI::error('The locked PDF was not reused from the stored text.');
}

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$record->reviseDecision($decisionId, 'Föreningen köper modell Y.', null, null);
$afterEdit = $pdf->bytes($draftId);

if ($afterEdit !== $first || str_contains($afterEdit, 'modell Y')) {
    \WP_CLI::error('A live decision change rewrote the locked PDF.');
}

$draftMeetingId = $meetingService->schedule($typeId, 'LAB-PDF utkast', MeetingMoment::fromLocal('2024-06-02 18:00'), '');
$meetingService->markHeld($draftMeetingId);
$openDraftId = $drafts->create($draftMeetingId);

clean_user_cache($boardMember->ID);
wp_set_current_user($boardMember->ID);
$visible = $pdf->readable($draftId);

if ($visible->state() !== RevisionState::Finalized || $pdf->bytes($draftId) !== $first) {
    \WP_CLI::error('A board member could not read the locked PDF.');
}

$denied = false;

try {
    $pdf->bytes($openDraftId);
} catch (NotAllowed) {
    $denied = true;
}

if (! $denied) {
    \WP_CLI::error('A board member could export a draft PDF.');
}

wp_set_current_user(1);

foreach ([$meetingId, $draftMeetingId] as $id) {
    $wpdb->delete($revisions, ['meeting_id' => $id], ['%d']);
    $wpdb->delete($minutes, ['meeting_id' => $id], ['%d']);
    $wpdb->delete($decisions, ['meeting_id' => $id], ['%d']);
    $wpdb->delete($agenda, ['meeting_id' => $id], ['%d']);
    $wpdb->delete($meetings, ['id' => $id], ['%d']);
}

\WP_CLI::success('The minutes PDF is rendered from the stored revision.');
