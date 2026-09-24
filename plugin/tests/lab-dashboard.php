<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Application\Association\WorkOverview;
use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Domain\Association\AssociationProfile;
use Foreningssystem\Domain\Document\DocumentVisibility;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Infrastructure\WordPress\Plugin;
use Foreningssystem\Infrastructure\WordPress\PrivateUploadDirectory;
use Foreningssystem\Infrastructure\WordPress\WordpressAssociationProfile;
use Foreningssystem\Infrastructure\WordPress\WordpressBoard;
use Foreningssystem\Infrastructure\WordPress\WordpressDocuments;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$assignments = $wpdb->prefix . 'assoc_board_assignment';
$roles = $wpdb->prefix . 'assoc_board_role';
$meetings = $wpdb->prefix . 'assoc_meeting';
$actions = $wpdb->prefix . 'assoc_action_item';
$decisions = $wpdb->prefix . 'assoc_decision';
$notes = $wpdb->prefix . 'assoc_meeting_note';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$participants = $wpdb->prefix . 'assoc_meeting_participant';
$minutes = $wpdb->prefix . 'assoc_minutes';
$revisions = $wpdb->prefix . 'assoc_minutes_revision';
$documents = $wpdb->prefix . 'assoc_document';
$identities = $wpdb->prefix . 'assoc_personal_identity';
$organizations = $wpdb->prefix . 'assoc_organization';
$emails = [
    'lab-dashboard-anna@example.test',
    'lab-dashboard-karin@example.test',
    'lab-dashboard-johan@example.test',
    'lab-dashboard-lisa@example.test',
    'lab-dashboard-eva@example.test',
    'lab-dashboard-nils@example.test',
    'lab-dashboard-hugo@example.test',
    'lab-dashboard-frida@example.test',
    'lab-dashboard-contact@example.test',
];
$roleSlugs = ['lab_dashboard_chair', 'lab_dashboard_treasurer', 'lab_dashboard_member'];
$previousProfile = null;

$cleanup = static function () use (
    $wpdb,
    $people,
    $assignments,
    $roles,
    $meetings,
    $actions,
    $decisions,
    $notes,
    $agenda,
    $participants,
    $minutes,
    $revisions,
    $documents,
    $identities,
    $organizations,
    $emails,
    $roleSlugs,
    &$previousProfile
): void {
    $meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-DASH %'));

    if (is_array($meetingIds)) {
        foreach ($meetingIds as $meetingId) {
            $meetingId = (int) $meetingId;
            $wpdb->delete($actions, ['meeting_id' => $meetingId], ['%d']);
            $wpdb->delete($decisions, ['meeting_id' => $meetingId], ['%d']);
            $wpdb->delete($notes, ['meeting_id' => $meetingId], ['%d']);
            $wpdb->delete($agenda, ['meeting_id' => $meetingId], ['%d']);
            $wpdb->delete($participants, ['meeting_id' => $meetingId], ['%d']);
            $wpdb->delete($revisions, ['meeting_id' => $meetingId], ['%d']);
            $wpdb->delete($minutes, ['meeting_id' => $meetingId], ['%d']);
            $wpdb->delete($meetings, ['id' => $meetingId], ['%d']);
        }
    }

    $stored = $wpdb->get_results($wpdb->prepare("SELECT storage_name FROM {$documents} WHERE title LIKE %s", 'LAB-DASH %'), ARRAY_A);
    $directory = PrivateUploadDirectory::path();

    if (is_array($stored)) {
        foreach ($stored as $row) {
            $name = is_array($row) ? (string) ($row['storage_name'] ?? '') : '';

            if (preg_match('/^document-[a-f0-9]{64}\.(pdf|jpg|png)$/', $name) === 1 && is_file($directory . '/' . $name)) {
                unlink($directory . '/' . $name);
            }
        }
    }

    $wpdb->query($wpdb->prepare("DELETE FROM {$documents} WHERE title LIKE %s", 'LAB-DASH %'));

    foreach ($roleSlugs as $slug) {
        $roleId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", $slug));

        if ($roleId) {
            $wpdb->delete($assignments, ['role_id' => (int) $roleId], ['%d']);
            $wpdb->delete($roles, ['id' => (int) $roleId], ['%d']);
        }
    }

    foreach ($emails as $email) {
        $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

        if ($personId) {
            $wpdb->delete($identities, ['person_id' => (int) $personId], ['%d']);
            $wpdb->delete($assignments, ['person_id' => (int) $personId], ['%d']);
            lab_delete_person_memberships((int) $personId);
            $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
        }
    }

    lab_delete_membership_numbers('LAB-DASH%');
    $wpdb->query($wpdb->prepare("DELETE FROM {$organizations} WHERE name = %s", 'LAB-DASH AB'));

    if ($previousProfile instanceof AssociationProfile) {
        wp_set_current_user(1);
        clean_user_cache(1);
        WordpressAssociationProfile::save($previousProfile);
    }
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();
$chair = get_user_by('login', 'lab-chair');

if (! $chair instanceof WP_User) {
    $fail('Missing lab chair');
}

$treasurer = lab_dashboard_user('lab-treasurer', RoleBundles::TREASURER);
wp_set_current_user(1);
clean_user_cache(1);
$previousProfile = WordpressAssociationProfile::load();
WordpressAssociationProfile::save(new AssociationProfile(
    'Östersunds Exempelförening',
    '',
    '',
    '',
    '',
    AssociationProfile::LANGUAGE_SWEDISH,
    null,
    1,
    1
));
$today = AssociationDate::fromIso(wp_date('Y-m-d'));
$now = MeetingMoment::fromLocal(wp_date('Y-m-d H:i:s'));
$yesterday = wp_date('Y-m-d', (int) current_datetime()->getTimestamp() - DAY_IN_SECONDS);
$nextWeek = wp_date('Y-m-d', (int) current_datetime()->getTimestamp() + (7 * DAY_IN_SECONDS));
$peopleService = WordpressPeople::service();
$beforeMembers = $peopleService->activeMemberCount($today);
$beforeMemberships = $peopleService->activeMembershipCount($today);
$beforeBoard = WordpressBoard::service()->currentCount($today);
$beforeDecisions = WordpressMeetings::record()->openCount();
$beforeTasks = WordpressMeetings::record()->openActionCount();
$anna = $peopleService->register('Anna', 'Andersson', 'lab-dashboard-anna@example.test', 'LAB-DASH-ANNA', 'ordinary', AssociationDate::fromIso('2024-01-01'), null, $today);
$karin = $peopleService->register('Karin', 'Nilsson', 'lab-dashboard-karin@example.test', 'LAB-DASH-KARIN', 'ordinary', AssociationDate::fromIso('2024-01-01'), null, $today);
$johan = $peopleService->register('Johan', 'Berg', 'lab-dashboard-johan@example.test', 'LAB-DASH-JOHAN', 'ordinary', AssociationDate::fromIso('2024-01-01'), null, $today);
$lisa = $peopleService->register('Lisa', 'Nilsson', 'lab-dashboard-lisa@example.test', 'LAB-DASH-LISA', 'ordinary', AssociationDate::fromIso('2024-01-01'), null, $today);
$eva = $peopleService->register('Eva', 'Familj', 'lab-dashboard-eva@example.test', 'LAB-DASH-FAMILY', 'family', AssociationDate::fromIso('2024-01-01'), null, $today);
$accountId = 0;

foreach ($peopleService->listPeople() as $record) {
    if ($record->person()->id() === $eva) {
        $accountId = (int) $record->account()?->id();
    }
}

$peopleService->addPersonToMembership($accountId, 'Nils', 'Familj', 'lab-dashboard-nils@example.test', AssociationDate::fromIso('2014-04-01'), AssociationDate::fromIso('2024-01-01'), ParticipantRole::Member, false, $today);
$hugo = $peopleService->register('Hugo', 'Historia', 'lab-dashboard-hugo@example.test', 'LAB-DASH-OLD', 'ordinary', AssociationDate::fromIso('2020-01-01'), null, $today);

foreach ($peopleService->listPeople() as $record) {
    if ($record->person()->id() === $hugo && $record->membership() !== null) {
        $peopleService->endMembership((int) $record->membership()->id(), AssociationDate::fromIso('2024-12-31'));
    }
}

$peopleService->register('Frida', 'Framtid', 'lab-dashboard-frida@example.test', 'LAB-DASH-FUTURE', 'ordinary', AssociationDate::fromIso('2027-06-01'), null, $today);
$contact = $peopleService->rememberPerson('Kim', 'Kontakt', 'lab-dashboard-contact@example.test', null, $today);
WordpressPeople::companies()->register('LAB-DASH AB', '', '', '', 'LAB-DASH-CO', AssociationDate::fromIso('2026-01-01'), $contact);

foreach ([
    'lab_dashboard_chair' => ['Chair', 0, 910],
    'lab_dashboard_treasurer' => ['Treasurer', 0, 920],
    'lab_dashboard_member' => ['Board member', 0, 940],
] as $slug => [$name, $multiple, $order]) {
    if ($wpdb->insert($roles, [
        'slug' => $slug,
        'name' => $name,
        'allows_multiple' => $multiple,
        'sort_order' => $order,
    ], ['%s', '%s', '%d', '%d']) === false) {
        $fail('A lab board role could not be created.');
    }
}

$chairRole = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", 'lab_dashboard_chair'));
$treasurerRole = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", 'lab_dashboard_treasurer'));
$memberRole = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", 'lab_dashboard_member'));
$board = WordpressBoard::service();
$board->place($anna, $chairRole, AssociationDate::fromIso('2024-01-01'), null, '', '', $today);
$board->place($karin, $treasurerRole, AssociationDate::fromIso('2024-01-01'), null, '', '', $today);
$board->place($johan, $memberRole, AssociationDate::fromIso('2024-01-01'), null, '', '', $today);
$board->place($lisa, $treasurerRole, AssociationDate::fromIso('2027-01-01'), null, '', '', $today);
$typeId = null;

foreach (WordpressMeetings::service()->types() as $type) {
    if ($type->slug() === 'board_meeting') {
        $typeId = $type->id();
    }
}

if ($typeId === null) {
    $fail('Board meeting type was not seeded.');
}

$finalizedId = WordpressMeetings::service()->schedule($typeId, 'LAB-DASH finalized', MeetingMoment::fromLocal('2099-08-12 18:00'), 'Hall');
WordpressMeetings::service()->markHeld($finalizedId);
$revisionId = WordpressMeetings::minutes()->create($finalizedId);
WordpressMeetings::minutes()->submit($revisionId, $finalizedId);
WordpressMeetings::minutes()->finalize($revisionId, $finalizedId);
$draftId = WordpressMeetings::service()->schedule($typeId, 'LAB-DASH draft', MeetingMoment::fromLocal('2026-09-12 18:00'), 'Hall');
WordpressMeetings::service()->markHeld($draftId);
WordpressMeetings::minutes()->create($draftId);
$inProgressId = WordpressMeetings::service()->schedule($typeId, 'LAB-DASH in progress', MeetingMoment::fromLocal($yesterday . ' 18:00'), 'Hall');
WordpressMeetings::service()->start($inProgressId);
WordpressMeetings::service()->schedule($typeId, 'LAB-DASH next', MeetingMoment::fromLocal($nextWeek . ' 18:00'), 'Hall');
WordpressMeetings::service()->schedule($typeId, 'LAB-DASH old plan', MeetingMoment::fromLocal('2020-02-01 18:00'), '');
$record = WordpressMeetings::record();
$record->addDecision($draftId, null, 'LAB-DASH decision open one', null, null);
$record->addDecision($draftId, null, 'LAB-DASH decision open two', null, null);
$doneDecision = $record->addDecision($draftId, null, 'LAB-DASH decision done', null, null);
$record->setFollowUp($doneDecision, DecisionFollowUp::Done, $draftId);
$record->addActionItem($draftId, null, 'LAB-DASH overdue', $karin, AssociationDate::fromIso('1970-01-02'));
$record->addActionItem($draftId, null, 'LAB-DASH due today', null, $today);
$record->addActionItem($draftId, null, 'LAB-DASH no date', null, null);
$doneTask = $record->addActionItem($draftId, null, 'LAB-DASH done', null, AssociationDate::fromIso('2020-01-02'));
$record->setActionStatus($doneTask, ActionStatus::Done, $draftId);
$pdf = "%PDF-1.4\n1 0 obj\nendobj\n%%EOF";
$archive = WordpressDocuments::archive();
$archive->add('LAB-DASH statutes oldest', $pdf, DocumentVisibility::Board);
$archive->add('LAB-DASH statutes two', $pdf, DocumentVisibility::Member);
$archive->add('LAB-DASH statutes three', $pdf, DocumentVisibility::Public);
$archive->add('LAB-DASH statutes four', $pdf, DocumentVisibility::Board);
$archive->add('LAB-DASH statutes five', $pdf, DocumentVisibility::Board);
$archive->add('LAB-DASH statutes newest', $pdf, DocumentVisibility::Board);
WordpressPeople::identity()->store($anna, '20120417-0011', 'Association administration', 'Association policy', $today, $today, (int) $chair->ID);
$storageNames = $wpdb->get_col($wpdb->prepare("SELECT storage_name FROM {$documents} WHERE title LIKE %s", 'LAB-DASH %'));
$members = $peopleService->activeMemberCount($today);
$memberships = $peopleService->activeMembershipCount($today);
$boardCount = WordpressBoard::service()->currentCount($today);
$decisionCount = $record->openCount();
$taskCount = $record->openActionCount();
clean_user_cache((int) $chair->ID);
wp_set_current_user((int) $chair->ID);
ob_start();
Plugin::renderAdminPage();
$html = (string) ob_get_clean();
$allMeetings = WordpressMeetings::service()->listMeetings();
$next = (new WorkOverview())->nextPlanned($allMeetings, $now);
$finalizedAt = strpos($html, 'Senast låsta protokollet');
$finalizedCard = $finalizedAt === false ? '' : substr($html, $finalizedAt, 600);

if (
    $members !== $beforeMembers + 6
    || $memberships !== $beforeMemberships + 6
    || $boardCount !== $beforeBoard + 3
    || $decisionCount !== $beforeDecisions + 2
    || $taskCount !== $beforeTasks + 3
    || ! str_contains($html, 'Östersunds Exempelförening')
    || ! str_contains($html, 'Aktiva enskilda medlemmar: ' . $members)
    || ! str_contains($html, 'Aktiva medlemskap: ' . $memberships)
    || ! str_contains($html, 'Styrelseuppdrag idag: ' . $boardCount)
    || ! str_contains($html, 'Öppna beslut: ' . $decisionCount)
    || ! str_contains($html, 'Öppna uppgifter: ' . $taskCount)
    || ! str_contains($html, 'LAB-DASH in progress')
    || ! str_contains($html, $yesterday)
    || $next === null
    || $next->title() === 'LAB-DASH old plan'
    || ! str_contains($html, $next->title())
    || str_contains($html, 'LAB-DASH old plan')
    || ! str_contains($html, 'LAB-DASH draft')
    || ! str_contains($html, 'Protokollutkast')
    || ! str_contains($finalizedCard, 'LAB-DASH finalized')
    || str_contains($finalizedCard, 'LAB-DASH draft')
    || ! str_contains($html, 'LAB-DASH overdue')
    || str_contains($html, 'LAB-DASH done')
    || str_contains($html, 'LAB-DASH due today')
    || str_contains($html, 'LAB-DASH no date')
    || ! str_contains($html, 'Anna Andersson')
    || ! str_contains($html, 'Karin Nilsson')
    || ! str_contains($html, 'Johan Berg')
    || ! str_contains($html, 'Lisa Nilsson')
    || ! str_contains($html, '2027-01-01')
    || ! str_contains($html, 'LAB-DASH statutes newest')
    || str_contains($html, 'LAB-DASH statutes oldest')
    || str_contains($html, '<form')
    || str_contains($html, 'Pluginet är aktivt.')
    || str_contains($html, '20120417-0011')
    || str_contains($html, '2012••••-0011')
    || str_contains($html, 'assoc-private')
) {
    $fail('The officer dashboard did not show the current association work. Members ' . $members . ' memberships ' . $memberships . ' board ' . $boardCount . '.');
}

if (is_array($storageNames)) {
    foreach ($storageNames as $storageName) {
        if (is_string($storageName) && $storageName !== '' && str_contains($html, $storageName)) {
            $fail('The dashboard showed a document storage name.');
        }
    }
}

clean_user_cache((int) $treasurer->ID);
wp_set_current_user((int) $treasurer->ID);
ob_start();
Plugin::renderAdminPage();
$restricted = (string) ob_get_clean();

if (
    ! str_contains($restricted, 'Östersunds Exempelförening')
    || ! str_contains($restricted, 'Aktiva enskilda medlemmar: ' . $members)
    || str_contains($restricted, 'LAB-DASH in progress')
    || str_contains($restricted, 'LAB-DASH draft')
    || str_contains($restricted, 'LAB-DASH finalized')
    || str_contains($restricted, 'LAB-DASH overdue')
    || str_contains($restricted, 'LAB-DASH statutes newest')
    || str_contains($restricted, 'LAB-DASH decision open one')
    || str_contains($restricted, '<form')
    || str_contains($restricted, '20120417-0011')
) {
    $fail('A treasurer could see meeting or document details on the dashboard.');
}

$cleanup();
\WP_CLI::success('The dashboard shows current association work and hides sections the treasurer cannot view.');

function lab_dashboard_user(string $login, string $role): WP_User
{
    $user = get_user_by('login', $login);

    if (! $user instanceof WP_User) {
        $created = wp_insert_user([
            'user_login' => $login,
            'user_pass' => wp_generate_password(24),
            'user_email' => $login . '@example.test',
            'role' => $role,
        ]);

        if (is_wp_error($created)) {
            \WP_CLI::error($created->get_error_message());
        }

        $user = get_user_by('id', $created);
    }

    if (! $user instanceof WP_User) {
        \WP_CLI::error('Could not create ' . $login);
    }

    $user->set_role($role);
    clean_user_cache((int) $user->ID);

    return get_user_by('id', (int) $user->ID);
}
