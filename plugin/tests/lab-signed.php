<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;

global $wpdb;

$meetings = $wpdb->prefix . 'assoc_meeting';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$decisions = $wpdb->prefix . 'assoc_decision';
$minutes = $wpdb->prefix . 'assoc_minutes';
$revisions = $wpdb->prefix . 'assoc_minutes_revision';
$copies = $wpdb->prefix . 'assoc_signed_copy';
$audit = $wpdb->prefix . 'assoc_audit_event';

$meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-SIGNED%'));

if (is_array($meetingIds)) {
    foreach ($meetingIds as $meetingId) {
        $revisionIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$revisions} WHERE meeting_id = %d", (int) $meetingId));

        if (is_array($revisionIds)) {
            foreach ($revisionIds as $revisionId) {
                $wpdb->delete($copies, ['revision_id' => (int) $revisionId], ['%d']);
                $wpdb->delete($audit, ['object_type' => 'minutes_revision', 'object_id' => (int) $revisionId], ['%s', '%d']);
            }
        }

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
$signed = WordpressMeetings::signedCopies();
$typeId = null;

foreach ($meetingService->types() as $type) {
    if ($type->slug() === 'board_meeting') {
        $typeId = $type->id();
    }
}

if ($typeId === null) {
    \WP_CLI::error('Board meeting type was not seeded.');
}

$meetingId = $meetingService->schedule($typeId, 'LAB-SIGNED styrelse', MeetingMoment::fromLocal('2024-05-02 18:00'), 'Lokalen');
$itemId = WordpressMeetings::workspace()->addAgendaItem($meetingId, 'Inköp', '');
$record->addDecision($meetingId, $itemId, 'Föreningen köper modell X.', null, null);
$meetingService->markHeld($meetingId);
$draftId = $drafts->create($meetingId);
$pdf = "%PDF-1.4\n1 0 obj\nendobj\n%%EOF";
$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 12);

clean_user_cache($chair->ID);
wp_set_current_user($chair->ID);
$blocked = false;

try {
    $signed->attach($draftId, $pdf, (int) $chair->ID);
} catch (MeetingRuleException) {
    $blocked = true;
}

if (! $blocked) {
    \WP_CLI::error('A draft accepted a signed copy.');
}

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$drafts->submit($draftId);
clean_user_cache($chair->ID);
wp_set_current_user($chair->ID);
$drafts->finalize($draftId);
$body = $drafts->revision($draftId)->body();

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$denied = false;

try {
    $signed->attach($draftId, $pdf, (int) $secretary->ID);
} catch (NotAllowed) {
    $denied = true;
}

if (! $denied) {
    \WP_CLI::error('A secretary could upload the signed copy.');
}

clean_user_cache($chair->ID);
wp_set_current_user($chair->ID);
$signed->attach($draftId, $pdf, (int) $chair->ID);
$signed->attach($draftId, $jpeg, (int) $chair->ID);
$after = $drafts->revision($draftId);

if ($after->body() !== $body || $after->id() !== $draftId || $signed->read($draftId) !== $jpeg) {
    \WP_CLI::error('Replacing the signed copy changed the minutes text.');
}

$replaced = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$audit} WHERE object_type = %s AND object_id = %d AND action = %s",
    'minutes_revision',
    $draftId,
    'replace_signed_copy'
));
$kept = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$copies} WHERE revision_id = %d AND replaced_by IS NOT NULL",
    $draftId
));

if ($replaced !== 1 || $kept !== 1) {
    \WP_CLI::error('Replacing the signed copy did not keep an audit event for the revision.');
}

clean_user_cache($boardMember->ID);
wp_set_current_user($boardMember->ID);
$visible = $signed->read($draftId);
$boardDenied = false;

try {
    $signed->attach($draftId, $pdf, (int) $boardMember->ID);
} catch (NotAllowed) {
    $boardDenied = true;
}

if ($visible !== $jpeg || ! $boardDenied) {
    \WP_CLI::error('A board member could replace the signed copy.');
}

wp_set_current_user(1);
$wpdb->delete($copies, ['revision_id' => $draftId], ['%d']);
$wpdb->delete($audit, ['object_type' => 'minutes_revision', 'object_id' => $draftId], ['%s', '%d']);
$wpdb->delete($revisions, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($minutes, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($decisions, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($agenda, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($meetings, ['id' => $meetingId], ['%d']);

\WP_CLI::success('The signed scan stays the original and the locked text stays put.');
