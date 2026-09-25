<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Document\DocumentVisibility;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\MemberDocumentsBlock;
use Foreningssystem\Infrastructure\WordPress\MembersScreen;
use Foreningssystem\Infrastructure\WordPress\PrivateUploadDirectory;
use Foreningssystem\Infrastructure\WordPress\WordpressDocuments;
use Foreningssystem\Infrastructure\WordPress\WordpressMemberAccounts;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$documents = $wpdb->prefix . 'assoc_document';
$organizations = $wpdb->prefix . 'assoc_organization';
$relationships = $wpdb->prefix . 'assoc_guardian_relationship';
$approvals = $wpdb->prefix . 'assoc_guardian_approval';
$emails = [
    'anna@example.test',
    'lisa@example.test',
    'erik@example.test',
    'johan@example.test',
    'karin@example.test',
    'kontakt@example.test',
    'guardian@example.test',
    'family@example.test',
    'nora@example.test',
    'tove@example.test',
    'ada-import@example.test',
    'lisa-import@example.test',
    'same-email@example.test',
];
$registration = get_option('users_can_register');
$preserved = [];
$preservedRows = $wpdb->get_results("SELECT id, wp_user_id FROM {$people}", ARRAY_A);

if (is_array($preservedRows)) {
    foreach ($preservedRows as $row) {
        if (is_array($row)) {
            $preserved[(int) $row['id']] = is_numeric($row['wp_user_id']) ? (int) $row['wp_user_id'] : null;
        }
    }
}

$existingUsers = [];

foreach (get_users(['fields' => ['ID']]) as $existingUser) {
    $existingUsers[] = (int) $existingUser->ID;
}

$cleanup = static function () use ($wpdb, $people, $documents, $organizations, $relationships, $approvals, $emails, $preserved, $existingUsers, $registration): void {
    $rows = $wpdb->get_results($wpdb->prepare("SELECT storage_name FROM {$documents} WHERE title LIKE %s", 'LAB-ACCOUNT%'), ARRAY_A);
    $directory = PrivateUploadDirectory::path();

    if (is_array($rows)) {
        foreach ($rows as $row) {
            $name = is_array($row) ? (string) ($row['storage_name'] ?? '') : '';

            if (preg_match('/^document-[a-f0-9]{64}\.(pdf|jpg|png)$/', $name) === 1 && is_file($directory . '/' . $name)) {
                unlink($directory . '/' . $name);
            }
        }
    }

    $wpdb->query($wpdb->prepare("DELETE FROM {$documents} WHERE title LIKE %s", 'LAB-ACCOUNT%'));
    lab_delete_membership_numbers('LAB-ACCT-%');
    $personIds = [];

    foreach ($emails as $email) {
        $found = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$people} WHERE LOWER(email) = LOWER(%s)", $email));

        if (is_array($found)) {
            foreach ($found as $foundId) {
                $personIds[] = (int) $foundId;
            }
        }
    }

    foreach (array_values(array_unique($personIds)) as $personId) {
        $wpdb->delete($relationships, ['child_person_id' => $personId], ['%d']);
        $wpdb->delete($relationships, ['guardian_person_id' => $personId], ['%d']);
        $wpdb->delete($approvals, ['child_person_id' => $personId], ['%d']);
        $wpdb->delete($approvals, ['guardian_person_id' => $personId], ['%d']);
        $wpdb->delete($people, ['id' => $personId], ['%d']);
    }

    $wpdb->query($wpdb->prepare("DELETE FROM {$organizations} WHERE name = %s", 'LAB Account AB'));
    require_once ABSPATH . 'wp-admin/includes/user.php';

    foreach (get_users(['fields' => ['ID', 'user_email', 'user_login']]) as $user) {
        $userId = (int) $user->ID;
        $login = (string) $user->user_login;
        $email = strtolower((string) $user->user_email);
        $createdHere = ! in_array($userId, $existingUsers, true);
        $labEmail = in_array($email, $emails, true) || str_starts_with($login, 'lab-acct-');
        $newMemberLogin = $createdHere && str_starts_with($login, 'assoc-member-');

        if ($labEmail || $newMemberLogin) {
            wp_delete_user($userId);
        }
    }

    foreach ($preserved as $personId => $userId) {
        $stillThere = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE id = %d", $personId));

        if (! is_numeric($stillThere)) {
            continue;
        }

        if ($userId === null) {
            $wpdb->query($wpdb->prepare("UPDATE {$people} SET wp_user_id = NULL WHERE id = %d", $personId));
        } else {
            $wpdb->update($people, ['wp_user_id' => $userId], ['id' => $personId], ['%d'], ['%d']);
        }
    }

    update_option('users_can_register', $registration);
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();

foreach ($emails as $email) {
    lab_account_clear_messages($email);
}

wp_set_current_user(1);
clean_user_cache(1);
require_once ABSPATH . 'wp-admin/includes/template.php';

$service = WordpressPeople::service();
$accounts = WordpressMemberAccounts::service();
$today = AssociationDate::fromIso(wp_date('Y-m-d'));
$yesterday = AssociationDate::fromIso(wp_date('Y-m-d', strtotime('-1 day')));
$minorBirth = AssociationDate::fromIso(wp_date('Y-m-d', strtotime('-17 years')));

if (wp_next_scheduled(WordpressMemberAccounts::HOOK) === false) {
    $fail('The daily member-account reconciliation is not scheduled.');
}

$noraUserId = wp_insert_user([
    'user_login' => 'lab-acct-nora',
    'user_pass' => wp_generate_password(24, true, true),
    'user_email' => 'nora@example.test',
    'display_name' => 'Nora Existing',
    'role' => 'editor',
]);

if (! is_int($noraUserId)) {
    $fail('The pre-existing WordPress user could not be created.');
}

$annaId = $service->register('Anna', 'Andersson', 'anna@example.test', 'LAB-ACCT-ANNA', 'ordinary', AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('1990-05-01'), $today);
WordpressMemberAccounts::provisionPerson($annaId);
$anna = $wpdb->get_row($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $annaId), ARRAY_A);
$annaUserId = is_array($anna) && is_numeric($anna['wp_user_id']) ? (int) $anna['wp_user_id'] : 0;
$annaUser = get_userdata($annaUserId);
$annaMessages = lab_account_messages('anna@example.test');
$annaSetup = lab_account_message_has_setup_link('anna@example.test');
$annaPassword = wp_generate_password(24, true, true);
wp_set_password($annaPassword, $annaUserId);
$annaAuthenticated = wp_authenticate('anna@example.test', $annaPassword);
unset($annaPassword);
wp_set_current_user($annaUserId);
clean_user_cache($annaUserId);
$officerCaps = ['view_members', 'edit_members', 'manage_board', 'view_internal_meetings', 'record_meeting', 'manage_documents', 'manage_association'];
$annaHasOfficerCap = false;

foreach ($officerCaps as $cap) {
    if (current_user_can($cap)) {
        $annaHasOfficerCap = true;
    }
}

wp_set_current_user(1);
clean_user_cache(1);
$archive = WordpressDocuments::archive();
$documentId = $archive->add('LAB-ACCOUNT stadgar', "%PDF-1.4\n1 0 obj\nendobj\n%%EOF", DocumentVisibility::Member);
wp_set_current_user($annaUserId);
clean_user_cache($annaUserId);
$activeRead = false;

try {
    $activeRead = str_starts_with($archive->read($documentId), '%PDF');
} catch (NotAllowed) {
    $activeRead = false;
}

$activeBlock = str_contains(MemberDocumentsBlock::render(), 'LAB-ACCOUNT stadgar');
wp_set_current_user(1);
clean_user_cache(1);
$service->endMembership(lab_period_id_for_number('LAB-ACCT-ANNA'), $yesterday);
$endedLink = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $annaId));
wp_set_current_user($annaUserId);
clean_user_cache($annaUserId);
$endedDenied = false;

try {
    $archive->read($documentId);
} catch (NotAllowed) {
    $endedDenied = true;
}

$endedBlock = MemberDocumentsBlock::render();
wp_set_current_user(1);
clean_user_cache(1);
$annaMembershipId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}assoc_membership WHERE membership_number = %s", 'LAB-ACCT-ANNA'));
$service->addPeriod($annaMembershipId, $today);
WordpressMemberAccounts::provisionMembership($annaMembershipId);
$reopenedLink = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $annaId));
$annaMessagesAfter = count(lab_account_messages('anna@example.test'));
$annaUsersAfter = count(get_users(['search' => 'anna@example.test', 'search_columns' => ['user_email']]));
wp_set_current_user($annaUserId);
clean_user_cache($annaUserId);
$reopenedRead = false;

try {
    $reopenedRead = str_starts_with($archive->read($documentId), '%PDF');
} catch (NotAllowed) {
    $reopenedRead = false;
}

wp_set_current_user(1);
clean_user_cache(1);
$lisaId = $service->register('Lisa', 'Lind', 'lisa@example.test', 'LAB-ACCT-LISA', 'youth', AssociationDate::fromIso('2020-01-01'), $minorBirth, $today);
WordpressMemberAccounts::provisionPerson($lisaId);
$lisaMessagesBefore = count(lab_account_messages('lisa@example.test'));
$lisaLink = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $lisaId));
$lisaMessages = lab_account_messages('lisa@example.test');
$lisaPage = lab_account_page($lisaId);
$erikId = $service->register('Erik', 'Familj', 'erik@example.test', 'LAB-ACCT-ERIK', 'family', AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('1991-03-03'), $today);
WordpressMemberAccounts::provisionPerson($erikId);
$erikLink = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $erikId));
$erikUser = get_userdata($erikLink);
$contactId = $service->rememberPerson('Kim', 'Kontakt', 'kontakt@example.test', null, $today);
WordpressPeople::companies()->register('LAB Account AB', '559123-4561', 'bolag@example.test', 'Testgatan 1', 'LAB-ACCT-CO', AssociationDate::fromIso('2020-01-01'), $contactId);
WordpressMemberAccounts::provisionPerson($contactId);
$contactLink = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $contactId));
$guardianId = $service->rememberPerson('Eva', 'Vardnad', 'guardian@example.test', AssociationDate::fromIso('1980-01-01'), $today);
WordpressPeople::guardians()->relate($lisaId, $guardianId, 'guardian', AssociationDate::fromIso('2020-01-01'));
WordpressMemberAccounts::provisionPerson($guardianId);
$guardianLink = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $guardianId));
$samId = $service->register('Sam', 'Delad', 'family@example.test', 'LAB-ACCT-SAM', 'ordinary', AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('1990-02-02'), $today);
$kimId = $service->register('Kim', 'Delad', 'family@example.test', 'LAB-ACCT-KIM', 'ordinary', AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('1992-02-02'), $today);
WordpressMemberAccounts::provisionPerson($samId);
WordpressMemberAccounts::provisionPerson($kimId);
$samLink = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $samId));
$kimLink = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $kimId));
$sharedPage = lab_account_page($samId);
$noraId = $service->register('Nora', 'Ny', 'nora@example.test', 'LAB-ACCT-NORA', 'ordinary', AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('1988-08-08'), $today);
WordpressMemberAccounts::provisionPerson($noraId);
$noraLinkBefore = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $noraId));
$noraUsers = count(get_users(['search' => 'nora@example.test', 'search_columns' => ['user_email']]));
$noraPage = lab_account_page($noraId);
$accounts->linkExisting($noraId, $noraUserId, $today);
$noraLinked = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $noraId));
$noraRoles = get_userdata($noraUserId);
wp_set_current_user($noraUserId);
clean_user_cache($noraUserId);
$noraRead = false;

try {
    $noraRead = str_starts_with($archive->read($documentId), '%PDF');
} catch (NotAllowed) {
    $noraRead = false;
}

wp_set_current_user(1);
clean_user_cache(1);
$accounts->unlink($noraId);
$noraAfterUnlink = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $noraId));
$noraStillExists = get_userdata($noraUserId) instanceof WP_User;
$noraRolesAfter = get_userdata($noraUserId);
$karinId = $service->register('Karin', 'Senare', 'karin@example.test', 'LAB-ACCT-KARIN', 'ordinary', AssociationDate::fromIso('2099-01-01'), AssociationDate::fromIso('1993-03-03'), $today);
WordpressMemberAccounts::provisionPerson($karinId);
$karinBefore = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $karinId));
$accounts->reconcile(AssociationDate::fromIso('2098-12-31'));
$karinStillWaiting = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $karinId));
$karinMessagesBefore = count(lab_account_messages('karin@example.test'));
$accounts->reconcile(AssociationDate::fromIso('2099-01-01'));
$karinUser = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $karinId));
$karinMessages = count(lab_account_messages('karin@example.test'));
$accounts->reconcile(AssociationDate::fromIso('2099-01-01'));
$karinUserAgain = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $karinId));
$karinMessagesAgain = count(lab_account_messages('karin@example.test'));
$toveId = $service->register('Tove', 'Arton', 'tove@example.test', 'LAB-ACCT-TOVE', 'ordinary', AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('2009-06-15'), $today);
$wpdb->query($wpdb->prepare("UPDATE {$people} SET wp_user_id = NULL WHERE id = %d", $toveId));
$toveUser = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $toveId));

if ($toveUser > 0) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($toveUser);
    $wpdb->query($wpdb->prepare("UPDATE {$people} SET wp_user_id = NULL WHERE id = %d", $toveId));
}

$accounts->reconcile(AssociationDate::fromIso('2027-06-14'));
$toveAtSeventeen = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $toveId));
$toveMessagesBefore = count(lab_account_messages('tove@example.test'));
$accounts->reconcile(AssociationDate::fromIso('2027-06-15'));
$toveAtEighteen = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $toveId));
$toveMessages = count(lab_account_messages('tove@example.test'));
$accounts->reconcile(AssociationDate::fromIso('2027-06-15'));
$toveAtEighteenAgain = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $toveId));
$toveMessagesAgain = count(lab_account_messages('tove@example.test'));
$import = <<<'CSV'
# foreningsplugin-members 2
membership;LAB-ACCT-IMP-ADULT;ordinary;
period;LAB-ACCT-IMP-ADULT;active;2020-01-01;;ordinary
participant;LAB-ACCT-IMP-ADULT;Ada;Import;ada-import@example.test;1990-01-01;member;1;2020-01-01;
membership;LAB-ACCT-IMP-MINOR;youth;
period;LAB-ACCT-IMP-MINOR;active;2020-01-01;;youth
participant;LAB-ACCT-IMP-MINOR;Lia;Import;lisa-import@example.test;2012-04-17;member;1;2020-01-01;
membership;LAB-ACCT-IMP-SHARED;ordinary;
period;LAB-ACCT-IMP-SHARED;active;2020-01-01;;ordinary
participant;LAB-ACCT-IMP-SHARED;Sam;Import;family@example.test;;member;1;2020-01-01;
CSV;
$importMessagesBefore = count(lab_account_messages('ada-import@example.test'));
$imported = WordpressPeople::exchange()->import($import);
$importAdult = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE email = %s", 'ada-import@example.test'));
$importMinor = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE email = %s", 'lisa-import@example.test'));
$importMessages = count(lab_account_messages('ada-import@example.test'));
WordpressPeople::exchange()->import($import);
$importAdultAgain = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE email = %s", 'ada-import@example.test'));
$importMessagesAgain = count(lab_account_messages('ada-import@example.test'));
$sameEmailUser = wp_insert_user([
    'user_login' => 'lab-acct-same-email',
    'user_pass' => wp_generate_password(24, true, true),
    'user_email' => 'same-email@example.test',
    'role' => 'subscriber',
]);
$sameEmailPerson = $service->rememberPerson('Otto', 'Los', 'same-email@example.test', AssociationDate::fromIso('1990-01-01'), $today);
$service->openForExistingPerson($sameEmailPerson, 'LAB-ACCT-SAME', 'ordinary', AssociationDate::fromIso('2020-01-01'));
$wpdb->query($wpdb->prepare("UPDATE {$people} SET wp_user_id = NULL WHERE id = %d", $sameEmailPerson));
wp_set_current_user(is_int($sameEmailUser) ? $sameEmailUser : 0);
clean_user_cache(is_int($sameEmailUser) ? $sameEmailUser : 0);
$sameEmailDenied = false;

try {
    $archive->read($documentId);
} catch (NotAllowed) {
    $sameEmailDenied = true;
}

wp_set_current_user(1);
clean_user_cache(1);
$annaPage = lab_account_page($annaId);
$johan = WordpressPeople::exchange()->import("first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on\nJohan;Historisk;johan@example.test;known;LAB-ACCT-JOHAN;ordinary;ended;2020-01-01;2024-01-01\n");
$johanLink = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE email = %s", 'johan@example.test'));
$annaUserRemains = get_userdata($annaUserId) instanceof WP_User;
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($annaUserId);
$staleLink = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $annaId));
$brokenPage = lab_account_page($annaId);
$brokenMailBefore = count(lab_account_messages('anna@example.test'));
$accounts->reconcile($today);
$accounts->reconcile($today);
$staleAfterReconcile = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $annaId));
$brokenMailAfter = count(lab_account_messages('anna@example.test'));
$annaUsersWhileBroken = count(get_users(['search' => 'anna@example.test', 'search_columns' => ['user_email']]));
$clearedBroken = $accounts->clearMissingLink($annaId);
$clearedLink = $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $annaId));
$replaced = $accounts->provision($annaId, $today);
$replacedUserId = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $annaId));
$replacedUser = get_userdata($replacedUserId);
$replacedMail = count(lab_account_messages('anna@example.test'));
$replacedAgain = $accounts->provision($annaId, $today);
$replacedUserAgain = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$people} WHERE id = %d", $annaId));
$replacedMailAgain = count(lab_account_messages('anna@example.test'));
$replacementPassword = wp_generate_password(24, true, true);
wp_set_password($replacementPassword, $replacedUserId);
$replacementAuthenticated = wp_authenticate('anna@example.test', $replacementPassword);
unset($replacementPassword);
wp_set_current_user($replacedUserId);
clean_user_cache($replacedUserId);
$replacementRead = false;

try {
    $replacementRead = str_starts_with($archive->read($documentId), '%PDF');
} catch (NotAllowed) {
    $replacementRead = false;
}

wp_set_current_user(1);
clean_user_cache(1);

$checks = [
    'anna user' => $annaUser instanceof WP_User && in_array('subscriber', $annaUser->roles, true) && $annaUser->user_email === 'anna@example.test',
    'anna link' => $annaUserId > 0,
    'anna officer caps' => $annaHasOfficerCap === false,
    'anna signed in' => $annaAuthenticated instanceof WP_User,
    'anna mail' => $annaMessages !== [] && $annaSetup === true,
    'anna page hides password' => ! str_contains($annaPage, 'type="password"') && ! str_contains($annaPage, 'assoc-member-'),
    'anna active document' => $activeRead === true && $activeBlock === true,
    'anna ended keeps link' => $endedLink === $annaUserId && $annaUserRemains === true && $endedDenied === true && ! str_contains($endedBlock, 'LAB-ACCOUNT stadgar'),
    'anna reopened' => $reopenedLink === $annaUserId && $reopenedRead === true && $annaMessagesAfter === count($annaMessages) && $annaUsersAfter === 1,
    'lisa minor' => $lisaLink === null && count($lisaMessages) === $lisaMessagesBefore && str_contains($lisaPage, 'Inget konto skapas automatiskt för medlemmar under 18 år.') && ! str_contains($lisaPage, 'assoc_create_member_account'),
    'erik family account' => $erikUser instanceof WP_User && $erikLink !== $annaUserId && $erikUser->user_email === 'erik@example.test',
    'company contact' => $contactLink === null && count(get_users(['search' => 'kontakt@example.test', 'search_columns' => ['user_email']])) === 0,
    'guardian only' => $guardianLink === null && count(get_users(['search' => 'guardian@example.test', 'search_columns' => ['user_email']])) === 0,
    'shared email' => $samLink === null && $kimLink === null && str_contains($sharedPage, 'Den här e-postadressen används av mer än en person.'),
    'existing wordpress email' => $noraLinkBefore === null && $noraUsers === 1 && str_contains($noraPage, 'Ett WordPress-konto använder redan den här e-postadressen.') && str_contains($noraPage, 'Nora Existing'),
    'explicit link' => $noraLinked === $noraUserId && $noraRoles instanceof WP_User && in_array('editor', $noraRoles->roles, true) && $noraRead === true,
    'unlink keeps user and role' => $noraAfterUnlink === null && $noraStillExists === true && $noraRolesAfter instanceof WP_User && in_array('editor', $noraRolesAfter->roles, true),
    'future member' => $karinBefore === null && $karinStillWaiting === null && $karinUser > 0 && $karinUserAgain === $karinUser && $karinMessages === $karinMessagesBefore + 1 && $karinMessagesAgain === $karinMessages,
    'turns 18' => $toveAtSeventeen === null && $toveAtEighteen > 0 && $toveAtEighteenAgain === $toveAtEighteen && $toveMessages === $toveMessagesBefore + 1 && $toveMessagesAgain === $toveMessages,
    'import rules' => $imported->errors() !== [] && $importAdult > 0 && $importMinor === null && $importAdultAgain === $importAdult && $importMessages === $importMessagesBefore + 1 && $importMessagesAgain === $importMessages,
    'johan historical' => $johan->errors() === [] && $johanLink === null,
    'same email without link' => $sameEmailDenied === true,
    'broken link stays' => $staleLink === $annaUserId && $staleAfterReconcile === $annaUserId && $annaUsersWhileBroken === 0 && $brokenMailAfter === $brokenMailBefore && str_contains($brokenPage, 'Det kopplade WordPress-kontot finns inte längre.') && str_contains($brokenPage, 'assoc_clear_broken_member_account') && ! str_contains($brokenPage, 'assoc_create_member_account'),
    'clear broken link' => $clearedBroken->outcome->value === 'broken_link_cleared' && $clearedLink === null,
    'replacement account' => $replaced->outcome->value === 'created' && $replacedUser instanceof WP_User && $replacedUserId !== $annaUserId && in_array('subscriber', $replacedUser->roles, true) && $replacedUserAgain === $replacedUserId && $replacedAgain->outcome->value === 'already_linked' && $replacedMail === $brokenMailAfter + 1 && $replacedMailAgain === $replacedMail && $replacementAuthenticated instanceof WP_User && $replacementRead === true,
    'registration unchanged' => get_option('users_can_register') === $registration,
];
$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => $passed !== true));

if ($failed !== []) {
    $fail('Member account lab failed: ' . implode(', ', $failed));
}

$cleanup();
\WP_CLI::success('Member account provisioning passed.');

function lab_account_clear_messages(string $email): void
{
    $ids = [];

    foreach (lab_account_messages($email) as $message) {
        $id = is_array($message) ? (string) ($message['ID'] ?? '') : '';

        if ($id !== '' && ! in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    if ($ids === []) {
        return;
    }

    wp_remote_request('http://mailpit:8025/api/v1/messages', [
        'method' => 'DELETE',
        'timeout' => 5,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode(['IDs' => $ids]),
    ]);
}

function lab_account_messages(string $email): array
{
    $response = wp_remote_get('http://mailpit:8025/api/v1/search?query=' . rawurlencode('to:' . $email), ['timeout' => 5]);

    if (is_wp_error($response)) {
        return [];
    }

    $payload = json_decode((string) wp_remote_retrieve_body($response), true);
    $messages = is_array($payload) ? ($payload['messages'] ?? null) : null;

    return is_array($messages) ? $messages : [];
}

function lab_account_message_has_setup_link(string $email): bool
{
    foreach (lab_account_messages($email) as $message) {
        $id = is_array($message) ? (string) ($message['ID'] ?? '') : '';

        if ($id === '') {
            continue;
        }

        $response = wp_remote_get('http://mailpit:8025/api/v1/message/' . rawurlencode($id), ['timeout' => 5]);

        if (is_wp_error($response)) {
            continue;
        }

        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        $text = is_array($payload) ? (string) ($payload['Text'] ?? '') . (string) ($payload['HTML'] ?? '') : '';

        if (str_contains($text, 'wp-login.php') && str_contains($text, 'action=rp')) {
            return true;
        }
    }

    return false;
}

function lab_account_page(int $personId): string
{
    $_GET['assoc_person'] = (string) $personId;
    $_GET['page'] = 'foreningsplugin-members';
    ob_start();
    MembersScreen::render(true);

    return (string) ob_get_clean();
}
