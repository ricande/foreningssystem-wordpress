<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Privacy\RetentionPeriod;
use Foreningssystem\Infrastructure\WordPress\PrivateUploadDirectory;
use Foreningssystem\Infrastructure\WordPress\RetentionPage;
use Foreningssystem\Infrastructure\WordPress\WordpressBoard;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;
use Foreningssystem\Infrastructure\WordPress\WordpressRetention;

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
$emails = ['ada-retain@example.test', 'grace-retain@example.test', 'kim-retain@example.test'];
$numbers = ['LAB-RETAIN-A', 'LAB-RETAIN-G', 'LAB-RETAIN-K'];
$pdf = "%PDF-1.4\n1 0 obj\nendobj\n%%EOF";

$cleanup = static function () use ($wpdb, $people, $memberships, $assignments, $roles, $participants, $meetings, $agenda, $decisions, $minutes, $revisions, $copies, $audit, $emails, $numbers): void {
    $meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-RETAIN%'));

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
        $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

        if ($personId) {
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

    $wpdb->query($wpdb->prepare("DELETE FROM {$audit} WHERE action IN (%s, %s)", 'lab_retain_old', 'lab_retain_recent'));
    $wpdb->delete($roles, ['slug' => 'lab_retain_seat'], ['%s']);
    update_option(WordpressRetention::OPTION, RetentionPeriod::DEFAULT_YEARS);
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();

if ($wpdb->insert($roles, [
    'slug' => 'lab_retain_seat',
    'name' => 'Labbstol kvar',
    'allows_multiple' => 1,
    'sort_order' => 17,
], ['%s', '%s', '%d', '%d']) === false) {
    \WP_CLI::error('The retention lab role could not be created.');
}

$roleId = (int) $wpdb->insert_id;
$today = AssociationDate::fromIso(wp_date('Y-m-d'));
$startedOn = $today->minusYears(8);
$oldEnd = $today->minusYears(6);
$recentStart = $today->minusYears(3);
$recentEnd = $today->minusYears(1);
wp_set_current_user(1);
clean_user_cache(1);
$peopleService = WordpressPeople::service();
$ada = $peopleService->register('Ada', 'Retain', 'ada-retain@example.test', 'LAB-RETAIN-A', 'ordinarie', $startedOn);
$grace = $peopleService->register('Grace', 'Hemlig', 'grace-retain@example.test', 'LAB-RETAIN-G', 'ordinarie', $recentStart);
$kim = $peopleService->register('Kim', 'Aktiv', 'kim-retain@example.test', 'LAB-RETAIN-K', 'ordinarie', $recentEnd);
WordpressBoard::service()->place($ada, $roleId, $startedOn, null, 'ada-retain@example.test', '2018');
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

$meetingId = $meetingService->schedule($typeId, 'LAB-RETAIN möte', MeetingMoment::fromLocal($startedOn->iso() . ' 18:00'), 'Lokalen');
WordpressMeetings::workspace()->addParticipant($meetingId, $ada, Presence::Present, MeetingDuty::None);
$itemId = WordpressMeetings::workspace()->addAgendaItem($meetingId, 'Inköp', '');
WordpressMeetings::record()->addDecision($meetingId, $itemId, 'HEMLIGT-KVARHALLNING nämner Ada Retain.', null, null);
$meetingService->markHeld($meetingId);
$revisionId = WordpressMeetings::minutes()->create($meetingId);
WordpressMeetings::minutes()->submit($revisionId);
WordpressMeetings::minutes()->finalize($revisionId);
WordpressMeetings::signedCopies()->attach($revisionId, $pdf, 1);
$adaMembership = lab_period_id_for_number('LAB-RETAIN-A');
$graceMembership = lab_period_id_for_number('LAB-RETAIN-G');
$peopleService->endMembership($adaMembership, $oldEnd);
$peopleService->endMembership($graceMembership, $recentEnd);

if ($wpdb->query($wpdb->prepare("UPDATE {$people} SET wp_user_id = %d WHERE id = %d", 880000 + $ada, $ada)) === false) {
    $fail('The account link could not be stored.');
}

if (
    $wpdb->insert($audit, [
        'object_type' => 'person',
        'object_id' => $ada,
        'action' => 'lab_retain_old',
        'actor_user_id' => 1,
        'created_at' => '2010-01-01 00:00:00',
    ], ['%s', '%d', '%s', '%d', '%s']) === false
    || $wpdb->insert($audit, [
        'object_type' => 'person',
        'object_id' => $grace,
        'action' => 'lab_retain_recent',
        'actor_user_id' => 1,
        'created_at' => current_time('mysql', true),
    ], ['%s', '%d', '%s', '%d', '%s']) === false
) {
    $fail('The retention lab audit events could not be stored.');
}

$secretary = get_user_by('login', 'lab-secretary');

if (! $secretary instanceof \WP_User) {
    $fail('The secretary lab user is missing.');
}

$denied = false;
wp_set_current_user((int) $secretary->ID);
clean_user_cache((int) $secretary->ID);

try {
    WordpressRetention::save(new RetentionPeriod(8));
} catch (NotAllowed $error) {
    $denied = $error->getMessage() === Capabilities::MANAGE_ASSOCIATION;
}

wp_set_current_user(1);
clean_user_cache(1);
WordpressRetention::save(new RetentionPeriod(8));
$changed = WordpressRetention::load()->years();
WordpressRetention::save(RetentionPeriod::default());
ob_start();
RetentionPage::render();
$html = (string) ob_get_clean();
$result = WordpressRetention::applyToday();
$saved = $wpdb->get_row($wpdb->prepare("SELECT first_name, last_name, email, wp_user_id FROM {$people} WHERE id = %d", $ada), ARRAY_A);
$period = lab_period_for_person((int) $ada);
$assignment = $wpdb->get_row($wpdb->prepare("SELECT started_on, ended_on, public_contact, term_label FROM {$assignments} WHERE person_id = %d", $ada), ARRAY_A);
$body = (string) $wpdb->get_var($wpdb->prepare("SELECT body FROM {$revisions} WHERE id = %d", $revisionId));
$scan = WordpressMeetings::signedCopies()->read($revisionId);
$savedGrace = $wpdb->get_row($wpdb->prepare("SELECT first_name, email FROM {$people} WHERE id = %d", $grace), ARRAY_A);
$savedKim = $wpdb->get_row($wpdb->prepare("SELECT first_name, email FROM {$people} WHERE id = %d", $kim), ARRAY_A);
$kimStatus = (string) (lab_period_for_person((int) $kim)['status'] ?? '');
$oldAudit = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$audit} WHERE action = %s", 'lab_retain_old'));
$recentAudit = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$audit} WHERE action = %s AND object_id = %d", 'lab_retain_recent', $grace));
$anonymizedAudit = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT action FROM {$audit} WHERE object_type = %s AND object_id = %d AND action = %s",
    'person',
    $ada,
    'anonymize_person'
));

if (
    $denied !== true
    || $changed !== 8
    || WordpressRetention::load()->years() !== 5
    || wp_next_scheduled(WordpressRetention::HOOK) === false
    || ! str_contains($html, 'Låsta protokoll och signerade skanningar behålls')
    || ! str_contains($html, 'value="5"')
    || $result->anonymized() < 1
    || ! is_array($saved)
    || $saved['first_name'] !== 'Anonym'
    || $saved['last_name'] !== 'Medlem'
    || $saved['email'] !== ''
    || $saved['wp_user_id'] !== null
    || ! is_array($period)
    || $period['membership_number'] !== 'LAB-RETAIN-A'
    || $period['status'] !== 'ended'
    || $period['ended_on'] !== $oldEnd->iso()
    || ! is_array($assignment)
    || $assignment['public_contact'] !== ''
    || $assignment['started_on'] !== $startedOn->iso()
    || $assignment['ended_on'] !== $oldEnd->iso()
    || $assignment['term_label'] !== '2018'
    || ! str_contains($body, 'HEMLIGT-KVARHALLNING nämner Ada Retain.')
    || ! str_starts_with($scan, '%PDF')
    || ! is_array($savedGrace)
    || $savedGrace['first_name'] !== 'Grace'
    || $savedGrace['email'] !== 'grace-retain@example.test'
    || ! is_array($savedKim)
    || $savedKim['first_name'] !== 'Kim'
    || $savedKim['email'] !== 'kim-retain@example.test'
    || $kimStatus !== 'active'
    || $oldAudit !== 0
    || $recentAudit !== 1
    || $anonymizedAudit !== 'anonymize_person'
    || str_contains($anonymizedAudit, 'ada-retain@example.test')
) {
    $fail('Retention changed a kept record or left expired contact data in place.');
}

$cleanup();
\WP_CLI::success('Retention anonymizes expired contact data and leaves locked minutes and signed scans.');
