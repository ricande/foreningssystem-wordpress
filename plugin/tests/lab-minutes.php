<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$memberships = $wpdb->prefix . 'assoc_membership';
$meetings = $wpdb->prefix . 'assoc_meeting';
$participants = $wpdb->prefix . 'assoc_meeting_participant';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$notes = $wpdb->prefix . 'assoc_meeting_note';
$decisions = $wpdb->prefix . 'assoc_decision';
$actions = $wpdb->prefix . 'assoc_action_item';
$minutes = $wpdb->prefix . 'assoc_minutes';
$revisions = $wpdb->prefix . 'assoc_minutes_revision';

$meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-MINUTES%'));

if (is_array($meetingIds)) {
    foreach ($meetingIds as $meetingId) {
        $wpdb->delete($revisions, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($minutes, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($actions, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($decisions, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($notes, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($participants, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($agenda, ['meeting_id' => (int) $meetingId], ['%d']);
        $wpdb->delete($meetings, ['id' => (int) $meetingId], ['%d']);
    }
}

$personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", 'lab-minutes-ada@example.test'));

if ($personId) {
    $wpdb->delete($actions, ['assignee_person_id' => (int) $personId], ['%d']);
    $wpdb->delete($participants, ['person_id' => (int) $personId], ['%d']);
    $wpdb->delete($memberships, ['person_id' => (int) $personId], ['%d']);
    $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
}

wp_set_current_user(1);
$ada = WordpressPeople::service()->register('Ada', 'Protokoll', 'lab-minutes-ada@example.test', 'LAB-MINUTES-1', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$secretary = get_user_by('login', 'lab-secretary');

if (! $secretary instanceof WP_User) {
    \WP_CLI::error('Lab secretary is missing.');
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

$meetingId = $meetingService->schedule($typeId, 'LAB-MINUTES styrelse', MeetingMoment::fromLocal('2024-05-02 18:00'), 'Lokalen');
$workspace->addParticipant($meetingId, $ada, Presence::Present, MeetingDuty::Chair);
$itemId = $workspace->addAgendaItem($meetingId, 'Inköp', '§2');
$record->addNote($meetingId, $itemId, 'Tre offerter granskades.', true);
$record->addNote($meetingId, $itemId, 'Intern skiss.', false);
$decisionId = $record->addDecision($meetingId, $itemId, 'Föreningen köper modell X.', null, null);
$record->addActionItem($meetingId, $itemId, 'Ada kontaktar kommunen om hyresavtalet.', $ada, AssociationDate::fromIso('2026-11-15'));

$tooEarly = false;

try {
    $drafts->create($meetingId);
} catch (MeetingRuleException) {
    $tooEarly = true;
}

if (! $tooEarly) {
    \WP_CLI::error('A planned meeting accepted a minutes draft.');
}

$meetingService->markHeld($meetingId);
$draftId = $drafts->create($meetingId);
$draft = $drafts->current($meetingId);

if (
    $draft === null
    || $draft->id() !== $draftId
    || $draft->state() !== RevisionState::Draft
    || $draft->handEdited()
    || ! str_contains($draft->body(), '§2. Inköp')
    || ! str_contains($draft->body(), 'Anteckning: Tre offerter granskades.')
    || ! str_contains($draft->body(), 'Beslut: Föreningen köper modell X.')
    || ! str_contains($draft->body(), 'Ada kontaktar kommunen om hyresavtalet.')
    || ! str_contains($draft->body(), 'Mötesordförande: Ada Protokoll')
    || str_contains($draft->body(), 'Intern skiss.')
    || str_contains($draft->body(), '@')
) {
    \WP_CLI::error('The draft did not copy the selected meeting text.');
}

$record->reviseDecision($decisionId, 'Föreningen köper modell Y.', null, null);
foreach ($record->actionItems($meetingId) as $row) {
    $actionId = $row->item()->id();

    if ($actionId !== null) {
        $record->setActionStatus($actionId, ActionStatus::Done);
    }
}

$copied = $drafts->current($meetingId);

if ($copied === null || $copied->body() !== $draft->body() || ! $drafts->isStale($meetingId)) {
    \WP_CLI::error('Live changes rewrote the minutes draft.');
}

$drafts->replaceBody($draftId, 'Egen text i utkastet.');

$replaced = false;

try {
    $drafts->regenerate($draftId, false);
} catch (MeetingRuleException) {
    $replaced = true;
}

if (! $replaced || $drafts->current($meetingId)?->body() !== 'Egen text i utkastet.') {
    \WP_CLI::error('A hand-edited draft was replaced without confirmation.');
}

$drafts->regenerate($draftId, true);
$fresh = $drafts->current($meetingId);

if (
    $fresh === null
    || $fresh->handEdited()
    || ! str_contains($fresh->body(), 'Beslut: Föreningen köper modell Y.')
    || ! str_contains($fresh->body(), 'klar')
    || str_contains($fresh->body(), 'Intern skiss.')
) {
    \WP_CLI::error('Regeneration did not copy the current meeting text.');
}

$held = null;

foreach ($meetingService->listMeetings() as $meeting) {
    if ($meeting->id() === $meetingId) {
        $held = $meeting->status();
    }
}

if ($held !== MeetingStatus::Held) {
    \WP_CLI::error('Creating a draft changed the meeting status.');
}

$boardMember = get_user_by('login', 'lab-board-member');

if (! $boardMember instanceof WP_User) {
    \WP_CLI::error('Lab board member is missing.');
}

clean_user_cache($boardMember->ID);
wp_set_current_user($boardMember->ID);
$visible = $drafts->current($meetingId);
$denied = false;

try {
    $drafts->replaceBody($draftId, 'Otillåten text.');
} catch (NotAllowed) {
    $denied = true;
}

if ($visible === null || ! str_contains($visible->body(), 'Föreningen köper modell Y.') || ! $denied) {
    \WP_CLI::error('A board member could change the minutes draft.');
}

wp_set_current_user(1);
$wpdb->delete($revisions, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($minutes, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($actions, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($decisions, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($notes, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($participants, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($agenda, ['meeting_id' => $meetingId], ['%d']);
$wpdb->delete($meetings, ['id' => $meetingId], ['%d']);
$personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", 'lab-minutes-ada@example.test'));

if ($personId) {
    $wpdb->delete($memberships, ['person_id' => (int) $personId], ['%d']);
    $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
}

\WP_CLI::success('A minutes draft keeps its copied text until it is explicitly regenerated.');
