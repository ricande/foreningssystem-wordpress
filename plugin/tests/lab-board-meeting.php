<?php

use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Infrastructure\WordPress\LatestBoardMeetingBlock;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;

global $wpdb;

$meetings = $wpdb->prefix . 'assoc_meeting';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$decisions = $wpdb->prefix . 'assoc_decision';
$minutes = $wpdb->prefix . 'assoc_minutes';
$revisions = $wpdb->prefix . 'assoc_minutes_revision';

$cleanup = static function () use ($wpdb, $meetings, $agenda, $decisions, $minutes, $revisions): void {
    $meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-BOARDMEET%'));

    if (! is_array($meetingIds)) {
        return;
    }

    foreach ($meetingIds as $meetingId) {
        $wpdb->delete($revisions, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($minutes, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($decisions, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($agenda, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($meetings, ['id' => (int) $meetingId], ['%d']);
    }
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();
$secretary = get_user_by('login', 'lab-secretary');
$chair = get_user_by('login', 'lab-chair');

if (! $secretary instanceof WP_User || ! $chair instanceof WP_User) {
    \WP_CLI::error('Lab users are missing.');
}

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$meetingService = WordpressMeetings::service();
$record = WordpressMeetings::record();
$drafts = WordpressMeetings::minutes();
$publication = WordpressMeetings::publication();
$boardTypeId = null;
$annualTypeId = null;

foreach ($meetingService->types() as $type) {
    if ($type->slug() === 'board_meeting') {
        $boardTypeId = $type->id();
    }

    if ($type->slug() === 'annual_meeting') {
        $annualTypeId = $type->id();
    }
}

if ($boardTypeId === null || $annualTypeId === null) {
    \WP_CLI::error('Meeting types were not seeded.');
}

$lock = static function (int $typeId, string $title, string $when, string $place) use ($meetingService, $record, $drafts): int {
    $meetingId = $meetingService->schedule($typeId, $title, MeetingMoment::fromLocal($when), $place);
    $itemId = WordpressMeetings::workspace()->addAgendaItem($meetingId, 'Inköp', '');
    $record->addDecision($meetingId, $itemId, 'HEMLIGT-BESLUT köper modell Z.', null, null);
    $meetingService->markHeld($meetingId);
    $revisionId = $drafts->create($meetingId);
    $drafts->submit($revisionId);

    return $revisionId;
};

$earlyId = $lock($boardTypeId, 'LAB-BOARDMEET tidig', '2024-05-02 18:00', 'Lokalen <A>');
$annualId = $lock($annualTypeId, 'LAB-BOARDMEET år', '2024-07-01 18:00', 'Lokalen');
$hiddenId = $lock($boardTypeId, 'LAB-BOARDMEET dolt', '2024-08-01 18:00', 'Lokalen');

clean_user_cache($chair->ID);
wp_set_current_user($chair->ID);
$drafts->finalize($earlyId);
$publication->publish($earlyId);
$drafts->finalize($annualId);
$publication->publish($annualId);
$drafts->finalize($hiddenId);

wp_set_current_user(0);
$shown = LatestBoardMeetingBlock::render();

if (
    ! str_contains($shown, 'LAB-BOARDMEET tidig')
    || ! str_contains($shown, '2024-05-02')
    || ! str_contains($shown, 'Lokalen &lt;A&gt;')
    || str_contains($shown, 'Lokalen <A>')
    || str_contains($shown, 'LAB-BOARDMEET år')
    || str_contains($shown, 'LAB-BOARDMEET dolt')
    || str_contains($shown, 'HEMLIGT-BESLUT')
    || str_contains($shown, 'assoc-private')
) {
    $fail('The public board meeting showed the wrong meeting or the minutes text.');
}

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$lateId = $lock($boardTypeId, 'LAB-BOARDMEET sen', '2024-09-01 18:00', '');
clean_user_cache($chair->ID);
wp_set_current_user($chair->ID);
$drafts->finalize($lateId);
$publication->publish($lateId);
wp_set_current_user(0);
$later = LatestBoardMeetingBlock::render();

if (! str_contains($later, 'LAB-BOARDMEET sen') || str_contains($later, 'LAB-BOARDMEET tidig') || str_contains($later, 'HEMLIGT-BESLUT')) {
    $fail('A later published board meeting did not replace the earlier one.');
}

wp_set_current_user($chair->ID);
$publication->unpublish($lateId);
wp_set_current_user(0);
$restored = LatestBoardMeetingBlock::render();

if (! str_contains($restored, 'LAB-BOARDMEET tidig') || str_contains($restored, 'LAB-BOARDMEET sen')) {
    $fail('Unpublishing the later meeting did not restore the earlier one.');
}

$cleanup();
\WP_CLI::success('The latest board meeting block shows title, date, and place.');
