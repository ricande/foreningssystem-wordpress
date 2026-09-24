<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$memberships = $wpdb->prefix . 'assoc_membership';
$meetings = $wpdb->prefix . 'assoc_meeting';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$decisions = $wpdb->prefix . 'assoc_decision';
$actions = $wpdb->prefix . 'assoc_action_item';

$meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-ACTION%'));

if (is_array($meetingIds)) {
    foreach ($meetingIds as $meetingId) {
        $wpdb->delete($actions, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($decisions, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($agenda, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($meetings, ['id' => (int) $meetingId], ['%d']);
    }
}

$personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", 'lab-action-ada@example.test'));

if ($personId) {
    $wpdb->delete($actions, ['assignee_person_id' => (int) $personId], ['%d']);
    $wpdb->delete($decisions, ['responsible_person_id' => (int) $personId], ['%d']);
    $wpdb->delete($memberships, ['person_id' => (int) $personId], ['%d']);
    $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
}

wp_set_current_user(1);
$ada = WordpressPeople::service()->register('Ada', 'Uppgift', 'lab-action-ada@example.test', 'LAB-ACTION-1', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
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

$meetingId = $meetingService->schedule($typeId, 'LAB-ACTION styrelse', MeetingMoment::fromLocal('2024-05-02 18:00'), '');
$itemId = $workspace->addAgendaItem($meetingId, 'Hyresavtal', '');
$decisionId = $record->addDecision($meetingId, $itemId, 'Föreningen förlänger hyresavtalet.', null, null);
$actionId = $record->addActionItem($meetingId, $itemId, 'Ada kontaktar kommunen om hyresavtalet.', $ada, AssociationDate::fromIso('2026-11-15'));
$record->setActionStatus($actionId, ActionStatus::Done);
$meetingService->markHeld($meetingId);
$record->addActionItem($meetingId, $itemId, 'Skicka underlaget efter mötet.', null, null);

$task = null;
$status = null;
$assignee = null;
$decisionWording = null;
$decisionFollowUp = null;
$openActions = 0;

foreach ($record->actionItems($meetingId) as $row) {
    if ($row->item()->id() === $actionId) {
        $task = $row->item()->task();
        $status = $row->item()->status();
        $assignee = $row->assigneeName();
    }

    if ($row->item()->status() === ActionStatus::Open) {
        $openActions++;
    }

    if ($row->assigneeName() !== null && str_contains($row->assigneeName(), '@')) {
        \WP_CLI::error('An action item showed a private email.');
    }
}

foreach ($record->decisions($meetingId) as $row) {
    if ($row->decision()->id() === $decisionId) {
        $decisionWording = $row->decision()->wording();
        $decisionFollowUp = $row->decision()->followUp();
    }
}

if (
    $task !== 'Ada kontaktar kommunen om hyresavtalet.'
    || $status !== ActionStatus::Done
    || $assignee !== 'Ada Uppgift'
    || $decisionWording !== 'Föreningen förlänger hyresavtalet.'
    || $decisionFollowUp !== DecisionFollowUp::Open
    || $openActions !== 1
) {
    \WP_CLI::error('Marking an action item done changed the task or the decision.');
}

$boardMember = get_user_by('login', 'lab-board-member');

if (! $boardMember instanceof WP_User) {
    \WP_CLI::error('Lab board member is missing.');
}

clean_user_cache($boardMember->ID);
wp_set_current_user($boardMember->ID);
$visible = false;

foreach ($record->actionItems($meetingId) as $row) {
    if ($row->item()->task() === 'Ada kontaktar kommunen om hyresavtalet.') {
        $visible = true;
    }
}

if (! $visible) {
    \WP_CLI::error('A board member could not read the action item.');
}

$denied = false;

try {
    $record->addActionItem($meetingId, $itemId, 'Otillåten uppgift.', null, null);
} catch (NotAllowed) {
    $denied = true;
}

if (! $denied) {
    \WP_CLI::error('A board member could record an action item.');
}

wp_set_current_user(1);
$wpdb->delete($actions, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($decisions, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($agenda, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($meetings, ['id' => $meetingId], ['%d']);
$personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", 'lab-action-ada@example.test'));

if ($personId) {
    $wpdb->delete($memberships, ['person_id' => (int) $personId], ['%d']);
    $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
}

\WP_CLI::success('An action item stays a task when its status changes.');
