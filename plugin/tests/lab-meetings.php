<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;

global $wpdb;

$meetings = $wpdb->prefix . 'assoc_meeting';
$wpdb->query($wpdb->prepare("DELETE FROM {$meetings} WHERE title LIKE %s", 'LAB-MEETING%'));

$boardMember = get_user_by('login', 'lab-board-member');

if (! $boardMember instanceof WP_User) {
    $created = wp_insert_user([
        'user_login' => 'lab-board-member',
        'user_pass' => wp_generate_password(24),
        'user_email' => 'lab-board-member@example.test',
        'role' => RoleBundles::BOARD_MEMBER,
    ]);

    if (is_wp_error($created)) {
        \WP_CLI::error($created->get_error_message());
    }

    $boardMember = get_user_by('id', $created);
}

if (! $boardMember instanceof WP_User) {
    \WP_CLI::error('Lab board member is missing.');
}

$boardMember->set_role(RoleBundles::BOARD_MEMBER);
clean_user_cache($boardMember->ID);

$secretary = get_user_by('login', 'lab-secretary');

if (! $secretary instanceof WP_User) {
    \WP_CLI::error('Lab secretary is missing.');
}

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$service = WordpressMeetings::service();
$typeId = null;

foreach ($service->types() as $type) {
    if ($type->slug() === 'board_meeting') {
        $typeId = $type->id();
    }
}

if ($typeId === null) {
    \WP_CLI::error('Board meeting type was not seeded.');
}

$meetingId = $service->schedule($typeId, 'LAB-MEETING styrelse', MeetingMoment::fromLocal('2024-05-02 18:00'), 'Föreningslokalen');
$service->start($meetingId);
$service->markHeld($meetingId);
$service->updateHeader($meetingId, $typeId, 'LAB-MEETING styrelse', MeetingMoment::fromLocal('2024-05-02 18:00'), 'Biblioteket');

$startedAgain = false;

try {
    $service->start($meetingId);
} catch (MeetingRuleException) {
    $startedAgain = true;
}

if (! $startedAgain) {
    \WP_CLI::error('A held meeting could be started again.');
}

$recordedId = $service->schedule($typeId, 'LAB-MEETING efterhand', MeetingMoment::fromLocal('2024-06-01 10:00'), '');
$service->markHeld($recordedId);
$held = $service->listMeetings();
$byTitle = [];

foreach ($held as $meeting) {
    $byTitle[$meeting->title()] = $meeting;
}

if (($byTitle['LAB-MEETING styrelse'] ?? null)?->status() !== MeetingStatus::Held || ($byTitle['LAB-MEETING styrelse'] ?? null)?->place() !== 'Biblioteket') {
    \WP_CLI::error('The held meeting lost its status when the place changed.');
}

if (($byTitle['LAB-MEETING efterhand'] ?? null)?->status() !== MeetingStatus::Held) {
    \WP_CLI::error('A meeting could not be recorded after the fact.');
}

clean_user_cache($boardMember->ID);
wp_set_current_user($boardMember->ID);
$visible = false;

foreach ($service->listMeetings() as $meeting) {
    if ($meeting->title() === 'LAB-MEETING styrelse') {
        $visible = true;
    }
}

if (! $visible) {
    \WP_CLI::error('A board member could not see the meeting.');
}

$denied = false;

try {
    $service->schedule($typeId, 'LAB-MEETING otillaten', MeetingMoment::fromLocal('2024-07-01 18:00'), '');
} catch (NotAllowed) {
    $denied = true;
}

if (! $denied) {
    \WP_CLI::error('A board member without manage_meetings could schedule a meeting.');
}

$wpdb->query($wpdb->prepare("DELETE FROM {$meetings} WHERE title LIKE %s", 'LAB-MEETING%'));

\WP_CLI::success('Meetings follow the planned, in-progress, and held lifecycle.');
