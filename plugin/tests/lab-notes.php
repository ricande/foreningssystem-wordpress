<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$memberships = $wpdb->prefix . 'assoc_membership';
$meetings = $wpdb->prefix . 'assoc_meeting';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$notes = $wpdb->prefix . 'assoc_meeting_note';
$decisions = $wpdb->prefix . 'assoc_decision';

$meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-NOTE%'));

if (is_array($meetingIds)) {
    foreach ($meetingIds as $meetingId) {
        $wpdb->delete($notes, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($decisions, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($agenda, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($meetings, ['id' => (int) $meetingId], ['%d']);
    }
}

$personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", 'lab-note-ada@example.test'));

if ($personId) {
    $wpdb->delete($decisions, ['responsible_person_id' => (int) $personId], ['%d']);
    $wpdb->delete($memberships, ['person_id' => (int) $personId], ['%d']);
    $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
}

wp_set_current_user(1);
$ada = WordpressPeople::service()->register('Ada', 'Lab', 'lab-note-ada@example.test', 'LAB-NOTE-1', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$secretary = get_user_by('login', 'lab-secretary');

if (! $secretary instanceof WP_User) {
    \WP_CLI::error('Lab secretary is missing.');
}

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$meetingService = WordpressMeetings::service();
$workspace = WordpressMeetings::workspace();
$record = WordpressMeetings::record();
$typeId = null;

foreach ($meetingService->types() as $type) {
    if ($type->slug() === 'board_meeting') {
        $typeId = $type->id();
    }
}

if ($typeId === null) {
    \WP_CLI::error('Board meeting type was not seeded.');
}

$meetingId = $meetingService->schedule($typeId, 'LAB-NOTE styrelse', MeetingMoment::fromLocal('2024-05-02 18:00'), '');
$itemId = $workspace->addAgendaItem($meetingId, 'Inköp', '');
$record->addNote($meetingId, $itemId, 'Tre offerter granskades.', true);
$record->addNote($meetingId, null, 'Lokalen var bokad.', false);
$decisionId = $record->addDecision($meetingId, $itemId, 'Föreningen köper modell X.', $ada, AssociationDate::fromIso('2026-10-30'));
$record->setFollowUp($decisionId, DecisionFollowUp::Done);
$meetingService->markHeld($meetingId);
$record->addNote($meetingId, $itemId, 'Sen anteckning.', false);

$wording = null;
$followUp = null;
$responsible = null;
$included = 0;

foreach ($record->decisions($meetingId) as $row) {
    if ($row->decision()->id() === $decisionId) {
        $wording = $row->decision()->wording();
        $followUp = $row->decision()->followUp();
        $responsible = $row->responsibleName();
    }
}

foreach ($record->notes($meetingId) as $note) {
    if ($note->includeInMinutes()) {
        $included++;
    }
}

$held = null;

foreach ($meetingService->listMeetings() as $meeting) {
    if ($meeting->id() === $meetingId) {
        $held = $meeting->status();
    }
}

$open = 0;

foreach ($record->decisions($meetingId) as $row) {
    if ($row->decision()->followUp() === DecisionFollowUp::Open) {
        $open++;
    }
}

if ($wording !== 'Föreningen köper modell X.' || $followUp !== DecisionFollowUp::Done || $responsible !== 'Ada Lab' || $included !== 1 || $held !== MeetingStatus::Held || $open !== 0) {
    \WP_CLI::error('The decision changed its wording when follow-up was updated.');
}

$boardMember = get_user_by('login', 'lab-board-member');

if (! $boardMember instanceof WP_User) {
    \WP_CLI::error('Lab board member is missing.');
}

clean_user_cache($boardMember->ID);
wp_set_current_user($boardMember->ID);
$visible = false;

foreach ($record->notes($meetingId) as $note) {
    if ($note->body() === 'Tre offerter granskades.') {
        $visible = true;
    }
}

if (! $visible) {
    \WP_CLI::error('A board member could not read the note.');
}

$denied = false;

try {
    $record->addNote($meetingId, $itemId, 'Otillåten anteckning.', false);
} catch (NotAllowed) {
    $denied = true;
}

if (! $denied) {
    \WP_CLI::error('A board member could record a note.');
}

wp_set_current_user(1);
$wpdb->delete($notes, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($decisions, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($agenda, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($meetings, ['id' => $meetingId], ['%d']);
$personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", 'lab-note-ada@example.test'));

if ($personId) {
    $wpdb->delete($memberships, ['person_id' => (int) $personId], ['%d']);
    $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
}

\WP_CLI::success('Notes stay working material and decisions keep their wording.');
