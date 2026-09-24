<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$memberships = $wpdb->prefix . 'assoc_membership';
$meetings = $wpdb->prefix . 'assoc_meeting';
$participants = $wpdb->prefix . 'assoc_meeting_participant';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$emails = ['lab-agenda-ada@example.test', 'lab-agenda-bo@example.test'];

$meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-AGENDA%'));

if (is_array($meetingIds)) {
    foreach ($meetingIds as $meetingId) {
        $wpdb->delete($participants, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($agenda, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($meetings, ['id' => (int) $meetingId], ['%d']);
    }
}

foreach ($emails as $email) {
    $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

    if ($personId) {
        $wpdb->delete($participants, ['person_id' => (int) $personId], ['%d']);
        lab_delete_person_memberships((int) $personId);
        $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
    }
}

wp_set_current_user(1);
$peopleService = WordpressPeople::service();
$ada = $peopleService->register('Ada', 'Lab', 'lab-agenda-ada@example.test', 'LAB-AGENDA-1', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$guest = $peopleService->register('Bo', 'Gäst', 'lab-agenda-bo@example.test', 'LAB-AGENDA-2', 'ordinarie', AssociationDate::fromIso('2024-01-01'));

$secretary = get_user_by('login', 'lab-secretary');

if (! $secretary instanceof WP_User) {
    \WP_CLI::error('Lab secretary is missing.');
}

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$meetingService = WordpressMeetings::service();
$workspace = WordpressMeetings::workspace();
$typeId = null;

foreach ($meetingService->types() as $type) {
    if ($type->slug() === 'board_meeting') {
        $typeId = $type->id();
    }
}

if ($typeId === null) {
    \WP_CLI::error('Board meeting type was not seeded.');
}

$meetingId = $meetingService->schedule($typeId, 'LAB-AGENDA styrelse', MeetingMoment::fromLocal('2024-05-02 18:00'), 'Föreningslokalen');
$workspace->addParticipant($meetingId, $ada, Presence::Present, MeetingDuty::Chair);
$workspace->addParticipant($meetingId, $guest, Presence::CoOpted, MeetingDuty::None);
$duplicate = false;

try {
    $workspace->addParticipant($meetingId, $ada, Presence::Absent, MeetingDuty::None);
} catch (MeetingRuleException) {
    $duplicate = true;
}

if (! $duplicate) {
    \WP_CLI::error('The same person was added to the meeting twice.');
}

$first = $workspace->addAgendaItem($meetingId, 'Öppnande', '');
$workspace->addAgendaItem($meetingId, 'Avslut', '');
$override = $workspace->addAgendaItem($meetingId, 'Val', '5 a');
$workspace->moveAgendaItem($first, 1);
$workspace->removeAgendaItem($override);
$meetingService->markHeld($meetingId);
$workspace->addAgendaItem($meetingId, 'Sen fråga', '');

$titles = [];
$numbers = [];

foreach ($workspace->agenda($meetingId) as $item) {
    $titles[] = $item->title();
    $numbers[] = $item->displayNumber();
}

if ($titles !== ['Avslut', 'Öppnande', 'Sen fråga'] || $numbers !== ['1', '2', '3']) {
    \WP_CLI::error('The agenda numbers did not follow the order.');
}

$held = null;

foreach ($meetingService->listMeetings() as $meeting) {
    if ($meeting->id() === $meetingId) {
        $held = $meeting;
    }
}

if ($held === null || $held->status() !== MeetingStatus::Held) {
    \WP_CLI::error('Adding a late agenda item changed the held meeting.');
}

$names = [];

foreach ($workspace->attendance($meetingId) as $row) {
    $names[] = $row->personName();

    if (str_contains($row->personName(), '@')) {
        \WP_CLI::error('Attendance shows a private email.');
    }
}

if (! in_array('Bo Gäst', $names, true) || ! in_array('Ada Lab', $names, true)) {
    \WP_CLI::error('The meeting is missing a participant.');
}

$boardMember = get_user_by('login', 'lab-board-member');

if (! $boardMember instanceof WP_User) {
    \WP_CLI::error('Lab board member is missing.');
}

clean_user_cache($boardMember->ID);
wp_set_current_user($boardMember->ID);
$visible = false;

foreach ($workspace->attendance($meetingId) as $row) {
    if ($row->personName() === 'Ada Lab') {
        $visible = true;
    }
}

if (! $visible) {
    \WP_CLI::error('A board member could not see the participants.');
}

$denied = false;

try {
    $workspace->addAgendaItem($meetingId, 'Otillåten punkt', '');
} catch (NotAllowed) {
    $denied = true;
}

if (! $denied) {
    \WP_CLI::error('A board member could change the agenda.');
}

wp_set_current_user(1);
$meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-AGENDA%'));

if (is_array($meetingIds)) {
    foreach ($meetingIds as $id) {
        $wpdb->delete($participants, ['meeting_id' => (int) $id], ['%d']);
        $wpdb->delete($agenda, ['meeting_id' => (int) $id], ['%d']);
        $wpdb->delete($meetings, ['id' => (int) $id], ['%d']);
    }
}

foreach ($emails as $email) {
    $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

    if ($personId) {
        $wpdb->delete($participants, ['person_id' => (int) $personId], ['%d']);
        lab_delete_person_memberships((int) $personId);
        $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
    }
}

\WP_CLI::success('Participants and agenda items belong to the meeting.');
