<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Document\DocumentVisibility;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Infrastructure\WordPress\MemberAreaBlock;
use Foreningssystem\Infrastructure\WordPress\PrivateUploadDirectory;
use Foreningssystem\Infrastructure\WordPress\WordpressDocuments;
use Foreningssystem\Infrastructure\WordPress\WordpressMemberAccounts;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$documents = $wpdb->prefix . 'assoc_document';
$identity = $wpdb->prefix . 'assoc_personal_identity';
$emails = [
    'mina-anna@example.test',
    'mina-erik@example.test',
    'mina-lisa@example.test',
    'mina-johan@example.test',
    'mina-nora@example.test',
    'mina-officer@example.test',
];
$pageId = 0;
$createdUsers = [];

$cleanup = static function () use ($wpdb, $people, $documents, $identity, $emails, &$pageId, &$createdUsers): void {
    $rows = $wpdb->get_results($wpdb->prepare("SELECT storage_name FROM {$documents} WHERE title = %s", 'LAB-MINA stadgar'), ARRAY_A);
    $directory = PrivateUploadDirectory::path();

    if (is_array($rows)) {
        foreach ($rows as $row) {
            $name = is_array($row) ? (string) ($row['storage_name'] ?? '') : '';

            if (preg_match('/^document-[a-f0-9]{64}\.(pdf|jpg|png)$/', $name) === 1 && is_file($directory . '/' . $name)) {
                unlink($directory . '/' . $name);
            }
        }
    }

    $wpdb->delete($documents, ['title' => 'LAB-MINA stadgar'], ['%s']);
    lab_delete_membership_numbers('LAB-MINA-%');

    foreach ($emails as $email) {
        $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

        if (is_numeric($personId)) {
            $wpdb->delete($identity, ['person_id' => (int) $personId], ['%d']);
            $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
        }
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';

    foreach ($createdUsers as $userId) {
        if ($userId > 1) {
            wp_delete_user($userId);
        }
    }

    foreach (['lab-mina-nora', 'lab-mina-officer'] as $login) {
        $user = get_user_by('login', $login);

        if ($user instanceof WP_User && (int) $user->ID > 1) {
            wp_delete_user((int) $user->ID);
        }
    }

    if ($pageId > 0) {
        wp_delete_post($pageId, true);
    }

    $existing = get_page_by_path('mina-sidor-lab');

    if ($existing instanceof WP_Post) {
        wp_delete_post((int) $existing->ID, true);
    }

    unset($_GET['person_id'], $_GET['membership_number'], $_POST['person_id'], $_POST['membership_number']);
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();
wp_set_current_user(1);
clean_user_cache(1);
$service = WordpressPeople::service();
$accounts = WordpressMemberAccounts::service();
$today = AssociationDate::fromIso(wp_date('Y-m-d'));
$yesterday = AssociationDate::fromIso(wp_date('Y-m-d', strtotime('-1 day')));
$familyStart = AssociationDate::fromIso('2024-01-01');
$annaJoin = AssociationDate::fromIso('2025-05-01');
$noraUserId = wp_insert_user([
    'user_login' => 'lab-mina-nora',
    'user_pass' => wp_generate_password(24, true, true),
    'user_email' => 'mina-nora@example.test',
    'display_name' => 'Nora Existing',
    'role' => 'subscriber',
]);
$officerUserId = wp_insert_user([
    'user_login' => 'lab-mina-officer',
    'user_pass' => wp_generate_password(24, true, true),
    'user_email' => 'mina-officer@example.test',
    'display_name' => 'Kim Officer',
    'role' => 'assoc_secretary',
]);

if (! is_int($noraUserId) || ! is_int($officerUserId)) {
    $fail('The lab WordPress users could not be created.');
}

$createdUsers[] = $noraUserId;
$createdUsers[] = $officerUserId;
$officerRolesBefore = get_userdata($officerUserId);
$officerCanRecordBefore = user_can($officerUserId, 'record_meeting');
$erikId = $service->register('Erik', 'Berg', 'mina-erik@example.test', 'LAB-MINA-F1042', 'family', $familyStart, AssociationDate::fromIso('1988-03-03'), $today);
$familyId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}assoc_membership WHERE membership_number = %s", 'LAB-MINA-F1042'));
$annaId = $service->addPersonToMembership($familyId, 'Anna', 'Berg', 'mina-anna@example.test', AssociationDate::fromIso('1990-05-01'), $annaJoin, ParticipantRole::Member, false, $today);
$lisaId = $service->addPersonToMembership($familyId, 'Lisa', 'Berg', 'mina-lisa@example.test', AssociationDate::fromIso('2014-02-02'), AssociationDate::fromIso('2024-06-01'), ParticipantRole::Member, false, $today);
$johanId = $service->register('Johan', 'Historisk', 'mina-johan@example.test', 'LAB-MINA-JOHAN', 'ordinary', AssociationDate::fromIso('2021-01-01'), AssociationDate::fromIso('1980-01-01'), $today);
$noraPersonId = $service->register('Nora', 'Hemlig', 'mina-nora@example.test', 'LAB-MINA-NORA', 'ordinary', AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('1992-02-02'), $today);
$officerPersonId = $service->register('Kim', 'Sekreterare', 'mina-officer@example.test', 'LAB-MINA-OFFICER', 'ordinary', AssociationDate::fromIso('2019-01-01'), AssociationDate::fromIso('1984-04-04'), $today);
WordpressPeople::identity()->store($annaId, '19900501-0005', 'member register', 'supplied by the member', $today, $today, 1);
WordpressMemberAccounts::provisionPerson($annaId);
WordpressMemberAccounts::provisionPerson($johanId);
$noraProvision = $accounts->provision($noraPersonId, $today);
$officerProvision = $accounts->provision($officerPersonId, $today);
$officerLink = $accounts->linkExisting($officerPersonId, $officerUserId, $today);
$service->endMembership(lab_period_id_for_number('LAB-MINA-JOHAN'), $yesterday);
$annaUserId = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $annaId));
$johanUserId = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $johanId));
$noraLink = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $noraPersonId));
$officerLinkId = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $officerPersonId));
$createdUsers[] = $annaUserId;
$createdUsers[] = $johanUserId;
$annaLogin = (string) get_userdata($annaUserId)->user_login;
$archive = WordpressDocuments::archive();
$documentId = $archive->add('LAB-MINA stadgar', "%PDF-1.4\n1 0 obj\nendobj\n%%EOF", DocumentVisibility::Member);
$existingPage = get_page_by_path('mina-sidor-lab');

if ($existingPage instanceof WP_Post) {
    wp_delete_post((int) $existingPage->ID, true);
}

$pageId = (int) wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_title' => 'Mina sidor lab',
    'post_name' => 'mina-sidor-lab',
    'post_content' => '<!-- wp:foreningsplugin/member-area /-->',
], true);
$page = get_post($pageId);

if (! $page instanceof WP_Post) {
    $fail('The member area page could not be created.');
}

$render = static function () use ($page): string {
    return do_blocks((string) $page->post_content);
};

wp_set_current_user(0);
clean_user_cache(0);
$loggedOut = $render();
wp_set_current_user($annaUserId);
clean_user_cache($annaUserId);
$_GET['person_id'] = (string) $erikId;
$_GET['membership_number'] = 'LAB-MINA-F1042';
$_POST['person_id'] = (string) $lisaId;
$annaHtml = MemberAreaBlock::render(['personId' => $erikId]);
$annaRead = false;

try {
    $annaRead = str_starts_with($archive->read($documentId), '%PDF');
} catch (NotAllowed) {
    $annaRead = false;
}

unset($_GET['person_id'], $_GET['membership_number'], $_POST['person_id']);
wp_set_current_user($johanUserId);
clean_user_cache($johanUserId);
$johanHtml = $render();
$johanDenied = false;

try {
    $archive->read($documentId);
} catch (NotAllowed) {
    $johanDenied = true;
}

wp_set_current_user($noraUserId);
clean_user_cache($noraUserId);
$noraHtml = $render();
$noraDenied = false;

try {
    $archive->read($documentId);
} catch (NotAllowed) {
    $noraDenied = true;
}

wp_set_current_user($officerUserId);
clean_user_cache($officerUserId);
$officerHtml = $render();
$officerRolesAfter = get_userdata($officerUserId);
$officerCanRecordAfter = user_can($officerUserId, 'record_meeting');
wp_set_current_user(1);
clean_user_cache(1);

$checks = [
    'page' => $pageId > 0 && has_block('foreningsplugin/member-area', $page),
    'logged out' => str_contains($loggedOut, 'Logga in för att se ditt medlemskap.') && str_contains($loggedOut, 'wp-login.php') && ! str_contains($loggedOut, 'Anna') && ! str_contains($loggedOut, 'mina-anna@example.test'),
    'anna portal' => str_contains($annaHtml, 'Mina uppgifter') && str_contains($annaHtml, 'Mitt medlemskap') && str_contains($annaHtml, 'Mina dokument') && str_contains($annaHtml, 'Min integritet') && str_contains($annaHtml, 'Mitt konto') && str_contains($annaHtml, 'Anna Berg') && str_contains($annaHtml, 'mina-anna@example.test') && str_contains($annaHtml, 'Medlemsstatus: Aktivt') && str_contains($annaHtml, 'LAB-MINA-F1042') && str_contains($annaHtml, 'Familj') && str_contains($annaHtml, '2025-05-01') && ! str_contains($annaHtml, '2024-01-01'),
    'anna family privacy' => ! str_contains($annaHtml, 'Erik') && ! str_contains($annaHtml, 'Lisa') && ! str_contains($annaHtml, 'Johan') && ! str_contains($annaHtml, 'mina-erik@example.test') && ! str_contains($annaHtml, 'mina-lisa@example.test'),
    'anna identity hidden' => ! str_contains($annaHtml, '19900501-0005') && ! str_contains($annaHtml, '199005010005') && ! str_contains($annaHtml, '900501-0005') && ! str_contains($annaHtml, $annaLogin) && str_contains($annaHtml, 'Personnummer: Registrerat') && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$identity} WHERE person_id = %d", $annaId)) === 1,
    'anna document' => $annaRead === true && str_contains($annaHtml, 'LAB-MINA stadgar'),
    'anna ignores person parameter' => ! str_contains($annaHtml, 'mina-erik@example.test') && ! str_contains($annaHtml, 'mina-lisa@example.test'),
    'johan history' => str_contains($johanHtml, 'Johan Historisk') && str_contains($johanHtml, 'Medlemsstatus: Inte aktivt') && str_contains($johanHtml, 'LAB-MINA-JOHAN') && str_contains($johanHtml, '2021-01-01') && str_contains($johanHtml, 'Medlemsdokument visas när ditt enskilda medlemskap är aktivt.') && ! str_contains($johanHtml, 'LAB-MINA stadgar') && $johanDenied === true,
    'nora unlinked' => $noraLink === null && $noraProvision->outcome->value === 'wordpress_email_conflict' && str_contains($noraHtml, 'Det här WordPress-kontot är inte kopplat till en medlem.') && ! str_contains($noraHtml, 'Nora Hemlig') && ! str_contains($noraHtml, 'Anna Berg') && $noraDenied === true,
    'officer' => $officerProvision->outcome->value === 'wordpress_email_conflict' && $officerLink->outcome->value === 'linked' && $officerLinkId === $officerUserId && $officerRolesBefore instanceof WP_User && in_array('assoc_secretary', $officerRolesBefore->roles, true) && $officerRolesAfter instanceof WP_User && $officerRolesAfter->roles === $officerRolesBefore->roles && $officerCanRecordBefore === true && $officerCanRecordAfter === true && str_contains($officerHtml, 'Kim Sekreterare') && str_contains($officerHtml, 'Medlemsstatus: Aktivt'),
    'cache' => defined('DONOTCACHEPAGE') && DONOTCACHEPAGE === true,
];
$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => $passed !== true));

if ($failed !== []) {
    $fail('Member area lab failed: ' . implode(', ', $failed));
}

$cleanup();
\WP_CLI::success('Mina sidor passed.');
