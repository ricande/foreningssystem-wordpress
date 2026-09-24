<?php

use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;
use Foreningssystem\Infrastructure\WordPress\WordpressBoard;
use Foreningssystem\Infrastructure\WordPress\WordpressPrivacy;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$memberships = $wpdb->prefix . 'assoc_membership';
$assignments = $wpdb->prefix . 'assoc_board_assignment';
$roles = $wpdb->prefix . 'assoc_board_role';
$participants = $wpdb->prefix . 'assoc_meeting_participant';
$meetings = $wpdb->prefix . 'assoc_meeting';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$decisions = $wpdb->prefix . 'assoc_decision';
$minutes = $wpdb->prefix . 'assoc_minutes';
$revisions = $wpdb->prefix . 'assoc_minutes_revision';
$audit = $wpdb->prefix . 'assoc_audit_event';
$emails = ['ada-privacy@example.test', 'grace-privacy@example.test'];

$cleanup = static function () use ($wpdb, $people, $memberships, $assignments, $roles, $participants, $meetings, $agenda, $decisions, $minutes, $revisions, $audit, $emails): void {
    foreach ($emails as $email) {
        $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

        if ($personId) {
            $wpdb->delete($audit, ['object_type' => 'person', 'object_id' => (int) $personId], ['%s', '%d']);
            $wpdb->delete($participants, ['person_id' => (int) $personId], ['%d']);
            $wpdb->delete($assignments, ['person_id' => (int) $personId], ['%d']);
            $wpdb->delete($memberships, ['person_id' => (int) $personId], ['%d']);
            $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
        }
    }

    $meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-PRIVACY%'));

    if (is_array($meetingIds)) {
        foreach ($meetingIds as $meetingId) {
            $wpdb->delete($participants, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($revisions, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($minutes, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($decisions, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($agenda, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($meetings, ['id' => (int) $meetingId], ['%d']);
        }
    }

    $wpdb->delete($roles, ['slug' => 'lab_privacy_seat'], ['%s']);
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();

if ($wpdb->insert($roles, [
    'slug' => 'lab_privacy_seat',
    'name' => 'Labbstol',
    'allows_multiple' => 0,
    'sort_order' => 15,
], ['%s', '%s', '%d', '%d']) === false) {
    \WP_CLI::error('The privacy lab role could not be created.');
}

$roleId = (int) $wpdb->insert_id;
wp_set_current_user(1);
$peopleService = WordpressPeople::service();
$ada = $peopleService->register('Ada', 'Privacy', 'ada-privacy@example.test', 'LAB-PRIVACY-A', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$grace = $peopleService->register('Grace', 'Hemlig', 'grace-privacy@example.test', 'LAB-PRIVACY-G', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
WordpressBoard::service()->place($ada, $roleId, AssociationDate::fromIso('2024-01-01'), null, 'ada-ordf@example.test', '');
$meetingService = WordpressMeetings::service();
$typeId = null;

foreach ($meetingService->types() as $type) {
    if ($type->slug() === 'board_meeting') {
        $typeId = $type->id();
    }
}

if ($typeId === null) {
    $fail('Board meeting type was not seeded.');
}

$meetingId = $meetingService->schedule($typeId, 'LAB-PRIVACY möte', MeetingMoment::fromLocal('2024-05-02 18:00'), 'Lokalen');
WordpressMeetings::workspace()->addParticipant($meetingId, $ada, Presence::Present, MeetingDuty::None);
WordpressMeetings::workspace()->addParticipant($meetingId, $grace, Presence::Absent, MeetingDuty::None);
$itemId = WordpressMeetings::workspace()->addAgendaItem($meetingId, 'Inköp', '');
WordpressMeetings::record()->addDecision($meetingId, $itemId, 'HEMLIGT-PROTOKOLL köper modell Z.', null, null);
$meetingService->markHeld($meetingId);
WordpressMeetings::minutes()->create($meetingId);

$registered = apply_filters('wp_privacy_personal_data_exporters', []);
$export = WordpressPrivacy::export('ada-privacy@example.test', 1);
$values = [];

foreach ($export['data'] as $group) {
    foreach ($group['data'] as $field) {
        $values[] = $field['value'];
    }
}

$text = implode("\n", $values);
$events = $wpdb->get_results($wpdb->prepare(
    "SELECT action FROM {$audit} WHERE object_type = %s AND object_id = %d",
    'person',
    $ada
), ARRAY_A);
$empty = WordpressPrivacy::export('nobody-privacy@example.test', 1);

if (
    ! isset($registered['foreningsplugin'])
    || $export['done'] !== true
    || ! str_contains($text, 'ada-privacy@example.test')
    || ! str_contains($text, 'LAB-PRIVACY-A')
    || ! str_contains($text, 'ada-ordf@example.test')
    || ! str_contains($text, 'LAB-PRIVACY möte')
    || ! str_contains($text, 'Närvarande')
    || ! str_contains($text, 'Protokoll kan innehålla ditt namn och behålls')
    || str_contains($text, 'grace-privacy@example.test')
    || str_contains($text, 'LAB-PRIVACY-G')
    || str_contains($text, 'Hemlig')
    || str_contains($text, 'HEMLIGT-PROTOKOLL')
    || ! is_array($events)
    || count($events) !== 1
    || (string) $events[0]['action'] !== 'export_personal_data'
    || $empty['data'] !== []
) {
    $fail('The privacy export included another person or the minutes text.');
}

$cleanup();
\WP_CLI::success('A personal data export stays with the requester and leaves the minutes out.');
