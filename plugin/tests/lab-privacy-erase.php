<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\PrivateUploadDirectory;
use Foreningssystem\Infrastructure\WordPress\WordpressBoard;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;
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
$copies = $wpdb->prefix . 'assoc_signed_copy';
$audit = $wpdb->prefix . 'assoc_audit_event';
$familyEmail = 'familjen-erase@example.test';
$emails = ['ada-erase@example.test', 'grace-erase@example.test', $familyEmail];
$numbers = ['LAB-ERASE-A', 'LAB-ERASE-G', 'LAB-ERASE-F1', 'LAB-ERASE-F2'];
$pdf = "%PDF-1.4\n1 0 obj\nendobj\n%%EOF";

$cleanup = static function () use ($wpdb, $people, $memberships, $assignments, $roles, $participants, $meetings, $agenda, $decisions, $minutes, $revisions, $copies, $audit, $emails, $numbers): void {
    $meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-ERASE%'));

    if (is_array($meetingIds)) {
        foreach ($meetingIds as $meetingId) {
            $revisionIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$revisions} WHERE meeting_id = %d", (int) $meetingId));

            if (is_array($revisionIds)) {
                foreach ($revisionIds as $revisionId) {
                    $names = $wpdb->get_col($wpdb->prepare("SELECT storage_name FROM {$copies} WHERE revision_id = %d", (int) $revisionId));

                    if (is_array($names)) {
                        foreach ($names as $name) {
                            if (preg_match('/^signed-\d+-[a-f0-9]{64}\.(pdf|jpg|png)$/', (string) $name) === 1) {
                                $path = PrivateUploadDirectory::path() . '/' . $name;

                                if (is_file($path)) {
                                    unlink($path);
                                }
                            }
                        }
                    }

                    $wpdb->delete($copies, ['revision_id' => (int) $revisionId], ['%d']);
                    $wpdb->delete($audit, ['object_type' => 'minutes_revision', 'object_id' => (int) $revisionId], ['%s', '%d']);
                }
            }

            $wpdb->delete($revisions, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($minutes, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($decisions, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($agenda, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($participants, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($meetings, ['id' => (int) $meetingId], ['%d']);
        }
    }

    $personIds = [];

    foreach ($emails as $email) {
        // A shared address can sit on several rows, so every match goes.
        $matches = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

        foreach (is_array($matches) ? $matches : [] as $personId) {
            $personIds[] = (int) $personId;
        }
    }

    foreach ($numbers as $number) {
        $personId = lab_person_id_for_membership_number((string) $number);

        if ($personId) {
            $personIds[] = (int) $personId;
        }
    }

    foreach (array_unique($personIds) as $personId) {
        $wpdb->delete($audit, ['object_type' => 'person', 'object_id' => $personId], ['%s', '%d']);
        $wpdb->delete($participants, ['person_id' => $personId], ['%d']);
        $wpdb->delete($assignments, ['person_id' => $personId], ['%d']);
        lab_delete_person_memberships((int) $personId);
        $wpdb->delete($people, ['id' => $personId], ['%d']);
    }

    $wpdb->delete($roles, ['slug' => 'lab_erase_seat'], ['%s']);
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();

if ($wpdb->insert($roles, [
    'slug' => 'lab_erase_seat',
    'name' => 'Labbraderare',
    'allows_multiple' => 1,
    'sort_order' => 16,
], ['%s', '%s', '%d', '%d']) === false) {
    \WP_CLI::error('The erase lab role could not be created.');
}

$roleId = (int) $wpdb->insert_id;
wp_set_current_user(1);
clean_user_cache(1);
$ada = WordpressPeople::service()->register('Ada', 'Erase', 'ada-erase@example.test', 'LAB-ERASE-A', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$grace = WordpressPeople::service()->register('Grace', 'Hemlig', 'grace-erase@example.test', 'LAB-ERASE-G', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
WordpressBoard::service()->place($ada, $roleId, AssociationDate::fromIso('2024-01-01'), null, 'ada-erase@example.test', '2024');
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

$meetingId = $meetingService->schedule($typeId, 'LAB-ERASE möte', MeetingMoment::fromLocal('2024-06-04 18:00'), 'Lokalen');
WordpressMeetings::workspace()->addParticipant($meetingId, $ada, Presence::Present, MeetingDuty::None);
WordpressMeetings::workspace()->addParticipant($meetingId, $grace, Presence::Present, MeetingDuty::None);
$itemId = WordpressMeetings::workspace()->addAgendaItem($meetingId, 'Inköp', '');
WordpressMeetings::record()->addDecision($meetingId, $itemId, 'HEMLIGT-RADERING nämner Ada Erase.', null, null);
$meetingService->markHeld($meetingId);
$revisionId = WordpressMeetings::minutes()->create($meetingId);
WordpressMeetings::minutes()->submit($revisionId);
WordpressMeetings::minutes()->finalize($revisionId);
WordpressMeetings::signedCopies()->attach($revisionId, $pdf, 1);

if ($wpdb->query($wpdb->prepare("UPDATE {$people} SET wp_user_id = %d WHERE id = %d", 880000 + $ada, $ada)) === false) {
    $fail('The account link could not be stored.');
}

$secretary = get_user_by('login', 'lab-secretary');

if (! $secretary instanceof \WP_User) {
    $fail('The secretary lab user is missing.');
}

wp_set_current_user((int) $secretary->ID);
clean_user_cache((int) $secretary->ID);
$denied = WordpressPrivacy::erase('ada-erase@example.test', 1);
wp_set_current_user(1);
clean_user_cache(1);
$stillAda = $wpdb->get_row($wpdb->prepare("SELECT first_name, email, wp_user_id FROM {$people} WHERE id = %d", $ada), ARRAY_A);
$skipped = WordpressPrivacy::erase('ada-erase@example.test', 2);
$registered = apply_filters('wp_privacy_personal_data_erasers', []);
$result = WordpressPrivacy::erase('ada-erase@example.test', 1);
$messages = implode("\n", $result['messages']);
// Two people behind one address. The request names nobody in particular.
$annaFamily = WordpressPeople::service()->register('Anna', 'Familj', $familyEmail, 'LAB-ERASE-F1', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$lisaFamily = WordpressPeople::service()->register('Lisa', 'Familj', $familyEmail, 'LAB-ERASE-F2', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$family = WordpressPrivacy::erase($familyEmail, 1);
$savedAnnaFamily = $wpdb->get_row($wpdb->prepare("SELECT first_name, email FROM {$people} WHERE id = %d", $annaFamily), ARRAY_A);
$savedLisaFamily = $wpdb->get_row($wpdb->prepare("SELECT first_name, email FROM {$people} WHERE id = %d", $lisaFamily), ARRAY_A);
$saved = $wpdb->get_row($wpdb->prepare("SELECT first_name, last_name, email, status, wp_user_id FROM {$people} WHERE id = %d", $ada), ARRAY_A);
$period = lab_period_for_person((int) $ada);
$assignment = $wpdb->get_row($wpdb->prepare("SELECT started_on, public_contact, term_label FROM {$assignments} WHERE person_id = %d", $ada), ARRAY_A);
$body = (string) $wpdb->get_var($wpdb->prepare("SELECT body FROM {$revisions} WHERE id = %d", $revisionId));
$storage = (string) $wpdb->get_var($wpdb->prepare("SELECT storage_name FROM {$copies} WHERE revision_id = %d AND replaced_by IS NULL", $revisionId));
$scan = WordpressMeetings::signedCopies()->read($revisionId);
$savedGrace = $wpdb->get_row($wpdb->prepare("SELECT first_name, email FROM {$people} WHERE id = %d", $grace), ARRAY_A);
$events = $wpdb->get_results($wpdb->prepare("SELECT action FROM {$audit} WHERE object_type = %s AND object_id = %d", 'person', $ada), ARRAY_A);

if (
    ! is_array($stillAda)
    || $stillAda['first_name'] !== 'Ada'
    || $stillAda['email'] !== 'ada-erase@example.test'
    || (int) $stillAda['wp_user_id'] !== 880000 + $ada
    || $denied['items_removed'] !== false
    || ! str_contains(implode("\n", $denied['messages']), 'behörighet')
    || $skipped['done'] !== true
    || $skipped['items_removed'] !== false
    || ! isset($registered['foreningsplugin'])
    || $result['done'] !== true
    || $result['items_removed'] !== true
    || $result['items_retained'] !== true
    || ! is_array($saved)
    || $saved['first_name'] !== 'Anonym'
    || $saved['last_name'] !== 'Medlem'
    || $saved['email'] !== ''
    || $saved['status'] !== 'known'
    || $saved['wp_user_id'] !== null
    || ! is_array($period)
    || $period['membership_number'] !== 'LAB-ERASE-A'
    || $period['status'] !== 'active'
    || $period['started_on'] !== '2024-01-01'
    || ! is_array($assignment)
    || $assignment['public_contact'] !== ''
    || $assignment['started_on'] !== '2024-01-01'
    || $assignment['term_label'] !== '2024'
    || ! str_contains($body, 'HEMLIGT-RADERING nämner Ada Erase.')
    || ! str_starts_with($scan, '%PDF')
    || ! preg_match('/^signed-\d+-[a-f0-9]{64}\.pdf$/', $storage)
    || ! is_array($savedGrace)
    || $savedGrace['first_name'] !== 'Grace'
    || $savedGrace['email'] !== 'grace-erase@example.test'
    || ! str_contains($messages, 'Kontaktuppgifterna är avidentifierade')
    || ! str_contains($messages, 'offentliga kontaktuppgiften')
    || ! str_contains($messages, 'Medlemsperioderna behålls')
    || ! str_contains($messages, 'Uppdragsdatum och roller behålls')
    || ! str_contains($messages, 'Namnet i ett låst protokoll behålls')
    || ! str_contains($messages, 'Den signerade skanningen behålls')
    || str_contains($messages, 'ada-erase@example.test')
    || str_contains($messages, 'HEMLIGT-RADERING')
    || ! is_array($events)
    || count($events) !== 1
    || (string) $events[0]['action'] !== 'anonymize_person'
    || str_contains((string) $events[0]['action'], 'ada-erase@example.test')
    || $family['items_removed'] !== false
    || $family['items_retained'] !== false
    || ! is_array($savedAnnaFamily)
    || $savedAnnaFamily['first_name'] !== 'Anna'
    || $savedAnnaFamily['email'] !== $familyEmail
    || ! is_array($savedLisaFamily)
    || $savedLisaFamily['first_name'] !== 'Lisa'
    || $savedLisaFamily['email'] !== $familyEmail
) {
    $fail('The privacy eraser rewrote a kept record or left a contact detail.');
}

$cleanup();
\WP_CLI::success('The privacy eraser clears contact details and reports the records it keeps.');
