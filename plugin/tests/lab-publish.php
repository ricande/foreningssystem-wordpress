<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\PublicationVisibility;
use Foreningssystem\Infrastructure\WordPress\LatestMinutesBlock;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;

global $wpdb;

$meetings = $wpdb->prefix . 'assoc_meeting';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$notes = $wpdb->prefix . 'assoc_meeting_note';
$decisions = $wpdb->prefix . 'assoc_decision';
$minutes = $wpdb->prefix . 'assoc_minutes';
$revisions = $wpdb->prefix . 'assoc_minutes_revision';

$meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-PUBLISH%'));

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
$record = WordpressMeetings::record();
$drafts = WordpressMeetings::minutes();
$publication = WordpressMeetings::publication();
$typeId = null;

foreach ($meetingService->types() as $type) {
    if ($type->slug() === 'board_meeting') {
        $typeId = $type->id();
    }
}

if ($typeId === null) {
    \WP_CLI::error('Board meeting type was not seeded.');
}

$meetingId = $meetingService->schedule($typeId, 'LAB-PUBLISH tidig', MeetingMoment::fromLocal('2024-05-02 18:00'), 'Lokalen');
$itemId = WordpressMeetings::workspace()->addAgendaItem($meetingId, 'Inköp', '');
$record->addNote($meetingId, $itemId, 'HEMLIG-ANTECKNING', false);
$record->addDecision($meetingId, $itemId, 'Föreningen köper modell <X>.', null, null);
$meetingService->markHeld($meetingId);
$revisionId = $drafts->create($meetingId);
$before = $drafts->revision($revisionId)->body();
$drafts->submit($revisionId);

clean_user_cache($chair->ID);
wp_set_current_user($chair->ID);
$drafts->finalize($revisionId);
$locked = $drafts->revision($revisionId)->body();

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$denied = false;

try {
    $publication->publish($revisionId);
} catch (NotAllowed) {
    $denied = true;
}

$hidden = LatestMinutesBlock::render();

if (! $denied || str_contains($hidden, 'modell') || str_contains($hidden, 'HEMLIG-ANTECKNING') || $locked !== $before) {
    \WP_CLI::error('An unpublished revision was shown, or the secretary could publish it.');
}

clean_user_cache($chair->ID);
wp_set_current_user($chair->ID);
$publication->publish($revisionId);
$published = $drafts->revision($revisionId);
$shown = LatestMinutesBlock::render();

if (
    $published->body() !== $locked
    || $published->visibility() !== PublicationVisibility::Public
    || ! str_contains($shown, 'LAB-PUBLISH tidig')
    || ! str_contains($shown, 'modell &lt;X&gt;')
    || str_contains($shown, 'modell <X>')
    || str_contains($shown, 'HEMLIG-ANTECKNING')
    || str_contains($shown, 'assoc-private')
) {
    \WP_CLI::error('Publishing changed the text or leaked a private note.');
}

$correctionId = $drafts->openCorrection($meetingId);
$stillShown = LatestMinutesBlock::render();

if (! str_contains($stillShown, 'modell &lt;X&gt;')) {
    \WP_CLI::error('An open correction hid the published revision.');
}

$drafts->submit($correctionId);
$drafts->finalize($correctionId);
$previous = $drafts->revision($revisionId);
$afterCorrection = LatestMinutesBlock::render();

if (
    $previous->visibility() !== PublicationVisibility::Board
    || $previous->body() !== $locked
    || $previous->supersededBy() !== $correctionId
    || str_contains($afterCorrection, 'modell')
) {
    \WP_CLI::error('A superseded revision stayed on the public site.');
}

$publication->publish($correctionId);
$replacement = LatestMinutesBlock::render();

if (! str_contains($replacement, 'modell &lt;X&gt;') || $drafts->revision($correctionId)->body() !== $locked) {
    \WP_CLI::error('The published correction did not keep the locked text.');
}

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$lateId = $meetingService->schedule($typeId, 'LAB-PUBLISH sen', MeetingMoment::fromLocal('2024-06-02 18:00'), '');
WordpressMeetings::workspace()->addAgendaItem($lateId, 'Avslut', '');
$meetingService->markHeld($lateId);
$lateRevisionId = $drafts->create($lateId);
$drafts->submit($lateRevisionId);
clean_user_cache($chair->ID);
wp_set_current_user($chair->ID);
$drafts->finalize($lateRevisionId);
$publication->publish($lateRevisionId);
$latest = LatestMinutesBlock::render();

if (! str_contains($latest, 'LAB-PUBLISH sen') || str_contains($latest, 'LAB-PUBLISH tidig')) {
    \WP_CLI::error('The public block did not show the later meeting.');
}

clean_user_cache($boardMember->ID);
wp_set_current_user($boardMember->ID);
$boardDenied = false;

try {
    $publication->unpublish($lateRevisionId);
} catch (NotAllowed) {
    $boardDenied = true;
}

if (! $boardDenied || ! str_contains(LatestMinutesBlock::render(), 'LAB-PUBLISH sen')) {
    \WP_CLI::error('A board member could change publication.');
}

wp_set_current_user(1);

foreach ([$meetingId, $lateId] as $id) {
    $wpdb->delete($revisions, ['meeting_id' => $id], ['%d']);
    $wpdb->delete($minutes, ['meeting_id' => $id], ['%d']);
    $wpdb->delete($decisions, ['meeting_id' => $id], ['%d']);
    $wpdb->delete($notes, ['meeting_id' => $id], ['%d']);
    $wpdb->delete($agenda, ['meeting_id' => $id], ['%d']);
    $wpdb->delete($meetings, ['id' => $id], ['%d']);
}

\WP_CLI::success('A published revision shows its locked text and hides it again when replaced.');
