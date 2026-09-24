<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Meeting\PublicationVisibility;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\PersonStatus;
use Foreningssystem\Infrastructure\WordPress\MeetingsPage;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$meetings = $wpdb->prefix . 'assoc_meeting';
$participants = $wpdb->prefix . 'assoc_meeting_participant';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$notes = $wpdb->prefix . 'assoc_meeting_note';
$decisions = $wpdb->prefix . 'assoc_decision';
$actions = $wpdb->prefix . 'assoc_action_item';
$minutes = $wpdb->prefix . 'assoc_minutes';
$revisions = $wpdb->prefix . 'assoc_minutes_revision';

$cleanup = static function () use ($wpdb, $people, $meetings, $participants, $agenda, $notes, $decisions, $actions, $minutes, $revisions): void {
    $meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title = %s", 'Board meeting — October'));

    if (is_array($meetingIds)) {
        foreach ($meetingIds as $meetingId) {
            $wpdb->delete($revisions, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($minutes, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($actions, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($decisions, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($notes, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($agenda, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($participants, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($meetings, ['id' => (int) $meetingId], ['%d']);
        }
    }

    foreach (['lab-workspace-anna@example.test', 'lab-workspace-karin@example.test', 'lab-workspace-johan@example.test', 'lab-workspace-nils@example.test'] as $email) {
        $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

        if ($personId) {
            lab_delete_person_memberships((int) $personId);
            $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
        }
    }
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();
wp_set_current_user(1);
$peopleService = WordpressPeople::service();
$anna = $peopleService->register('Anna', 'Workspace', 'lab-workspace-anna@example.test', 'LAB-WS-ANNA', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$karin = $peopleService->register('Karin', 'Workspace', 'lab-workspace-karin@example.test', 'LAB-WS-KARIN', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$johan = $peopleService->register('Johan', 'Workspace', 'lab-workspace-johan@example.test', 'LAB-WS-JOHAN', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$nils = $peopleService->register('Nils', 'Workspace', 'lab-workspace-nils@example.test', 'LAB-WS-NILS', 'ordinarie', AssociationDate::fromIso('2020-01-01'));
$peopleService->markDeceased($nils, AssociationDate::fromIso('2024-01-02'));
$secretary = get_user_by('login', 'lab-secretary');
$chair = get_user_by('login', 'lab-chair');
$boardMember = get_user_by('login', 'lab-board-member');

if (! $secretary instanceof WP_User || ! $chair instanceof WP_User || ! $boardMember instanceof WP_User) {
    $fail('Lab meeting users are missing.');
}

clean_user_cache((int) $secretary->ID);
wp_set_current_user((int) $secretary->ID);
$service = WordpressMeetings::service();
$workspace = WordpressMeetings::workspace();
$record = WordpressMeetings::record();
$typeId = null;

foreach ($service->types() as $type) {
    if ($type->slug() === 'board_meeting') {
        $typeId = $type->id();
    }
}

if ($typeId === null) {
    $fail('Board meeting type was not seeded.');
}

$meetingId = $service->schedule($typeId, 'Board meeting — October', MeetingMoment::fromLocal('2026-10-02 18:00'), 'Lokalen');
$workspace->addParticipant($meetingId, $anna, Presence::Present, MeetingDuty::Chair);
$workspace->addParticipant($meetingId, $karin, Presence::Present, MeetingDuty::None);
$workspace->addParticipant($meetingId, $johan, Presence::Absent, MeetingDuty::Adjuster);

try {
    $workspace->addParticipant($meetingId, $nils, Presence::Present, MeetingDuty::None);
    $fail('A deceased person was added.');
} catch (MeetingRuleException) {
}

$titles = ['Opening', 'Previous minutes', 'Equipment purchase', 'Other business', 'Closing'];
$itemIds = [];

foreach ($titles as $title) {
    $itemIds[$title] = $workspace->addAgendaItem($meetingId, $title, '');
}

$service->start($meetingId);
ob_start();
MeetingsPage::render();
$overview = (string) ob_get_clean();
$_GET['meeting'] = (string) $meetingId;
$_GET['agenda'] = (string) $itemIds['Equipment purchase'];
ob_start();
MeetingsPage::render();
$during = (string) ob_get_clean();
$_GET = [];
$record->addNote($meetingId, $itemIds['Equipment purchase'], 'Three offers were reviewed.', false);
$record->addDecision(
    $meetingId,
    $itemIds['Equipment purchase'],
    'The association purchases model X for no more than SEK 12,000.',
    $karin,
    AssociationDate::fromIso('2026-10-30')
);
$record->addActionItem($meetingId, $itemIds['Equipment purchase'], 'Karin places the order.', $karin, AssociationDate::fromIso('2026-10-30'));
$service->markHeld($meetingId);
$held = null;

foreach ($service->listMeetings() as $meeting) {
    if ($meeting->id() === $meetingId) {
        $held = $meeting->status();
    }
}

$beforeDraft = WordpressMeetings::minutes()->current($meetingId);
$draftId = WordpressMeetings::minutes()->create($meetingId);
$draft = WordpressMeetings::minutes()->current($meetingId);
WordpressMeetings::minutes()->replaceBody($draftId, (string) $draft?->body() . "\nHand edit.", $meetingId);
$regenerateBlocked = false;

try {
    WordpressMeetings::minutes()->regenerate($draftId, false, $meetingId);
} catch (MeetingRuleException) {
    $regenerateBlocked = true;
}

$edited = WordpressMeetings::minutes()->current($meetingId);
clean_user_cache((int) $chair->ID);
wp_set_current_user((int) $chair->ID);
WordpressMeetings::minutes()->submit($draftId, $meetingId);
WordpressMeetings::minutes()->finalize($draftId, $meetingId);
$finalized = WordpressMeetings::minutes()->current($meetingId);
$editBlocked = false;

try {
    WordpressMeetings::minutes()->replaceBody($draftId, 'Silent edit.', $meetingId);
} catch (MeetingRuleException | \RuntimeException) {
    $editBlocked = true;
}

$pdf = WordpressMeetings::pdf()->bytes($draftId);
$signed = WordpressMeetings::signedCopies()->attach($draftId, $pdf, (int) $chair->ID, $meetingId);
$afterUpload = WordpressMeetings::minutes()->current($meetingId);
WordpressMeetings::publication()->publish($draftId, $meetingId);
$published = WordpressMeetings::minutes()->current($meetingId);
clean_user_cache((int) $boardMember->ID);
wp_set_current_user((int) $boardMember->ID);
$_GET['meeting'] = (string) $meetingId;
ob_start();
MeetingsPage::render();
$readOnly = (string) ob_get_clean();
$_GET = [];
$wrongMeeting = false;

try {
    clean_user_cache((int) $secretary->ID);
    wp_set_current_user((int) $secretary->ID);
    $record->removeNote((int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$notes} WHERE meeting_id = %d", $meetingId)), $meetingId + 1);
} catch (MeetingRuleException) {
    $wrongMeeting = true;
}

$noteLeft = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$notes} WHERE meeting_id = %d", $meetingId));

if (
    ! str_contains($overview, 'assoc-meetings-in_progress')
    || ! str_contains($overview, 'Continue meeting') && ! str_contains($overview, 'Fortsätt mötet')
    || ! str_contains($during, 'id="agenda-' . $itemIds['Equipment purchase'] . '"')
    || ! str_contains($during, 'Equipment purchase')
    || ! str_contains($during, 'assoc_add_note')
    || str_contains($during, 'value="' . $nils . '"')
    || $held !== MeetingStatus::Held
    || $beforeDraft !== null
    || $draft === null
    || ! str_contains((string) $draft->body(), 'The association purchases model X for no more than SEK 12,000.')
    || ! str_contains((string) $draft->body(), 'Karin Workspace')
    || $regenerateBlocked !== true
    || $edited === null
    || ! str_contains($edited->body(), 'Hand edit.')
    || $finalized === null
    || $finalized->state() !== RevisionState::Finalized
    || $editBlocked !== true
    || ! str_starts_with($pdf, '%PDF')
    || $signed === ''
    || $afterUpload === null
    || $afterUpload->visibility() !== PublicationVisibility::Board
    || $published === null
    || $published->visibility() !== PublicationVisibility::Public
    || str_contains($readOnly, 'assoc_add_note')
    || ! str_contains($readOnly, 'Anna Workspace')
    || $wrongMeeting !== true
    || $noteLeft !== 1
    || ! current_user_can(Capabilities::VIEW_INTERNAL_MEETINGS)
) {
    $fail('The meeting workspace did not keep capture, minutes, and ownership together.');
}

$cleanup();
\WP_CLI::success('A secretary can prepare, record, and turn a meeting into minutes from one workspace.');
