<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Application\Decision\DecisionRegisterQuery;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\AssociationOverviewPage;
use Foreningssystem\Infrastructure\WordPress\DecisionsPage;
use Foreningssystem\Infrastructure\WordPress\Plugin;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;
use Foreningssystem\Infrastructure\WordPress\WpdbDecisionRepository;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$meetings = $wpdb->prefix . 'assoc_meeting';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$decisions = $wpdb->prefix . 'assoc_decision';
$actions = $wpdb->prefix . 'assoc_action_item';
$minutes = $wpdb->prefix . 'assoc_minutes';
$revisions = $wpdb->prefix . 'assoc_minutes_revision';
$titles = [
    'Lab decisions board meeting',
    'Lab decisions working meeting',
    'Lab decisions level meeting',
];

$cleanup = static function () use ($wpdb, $people, $meetings, $agenda, $decisions, $actions, $minutes, $revisions, $titles): void {
    foreach ($titles as $title) {
        $meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title = %s", $title));

        if (! is_array($meetingIds)) {
            continue;
        }

        foreach ($meetingIds as $meetingId) {
            $wpdb->delete($revisions, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($minutes, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($actions, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($decisions, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($agenda, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($meetings, ['id' => (int) $meetingId], ['%d']);
        }
    }

    foreach (['lab-decisions-anna@example.test', 'lab-decisions-erik@example.test'] as $email) {
        $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

        if ($personId) {
            lab_delete_person_memberships((int) $personId);
            $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
        }
    }

    lab_delete_membership_numbers('LAB-DEC-%');
    wp_set_current_user(1);
    $settingsUser = get_user_by('login', 'lab-decisions-settings');

    if ($settingsUser instanceof WP_User) {
        wp_delete_user((int) $settingsUser->ID);
    }

    remove_role('assoc_lab_decisions_settings');
    wp_set_current_user(1);
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$render = static function (array $query) use ($fail): string {
    $_GET = $query;
    $_POST = [];
    $_REQUEST = [];
    ob_start();

    try {
        DecisionsPage::render();
    } catch (RuntimeException $error) {
        ob_end_clean();
        $fail($error->getMessage());
    }

    return (string) ob_get_clean();
};

$cleanup();

try {
    wp_set_current_user(1);
    $today = AssociationDate::fromIso(wp_date('Y-m-d'));
    $yesterday = $today->previousDay();
    $future = $today->nextDay();
    $peopleService = WordpressPeople::service();
    $anna = $peopleService->register('Anna', 'Decisions', 'lab-decisions-anna@example.test', 'LAB-DEC-ANNA', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
    $erik = $peopleService->register('Erik', 'Decisions', 'lab-decisions-erik@example.test', 'LAB-DEC-ERIK', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
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

    $meetingA = $service->schedule($typeId, 'Lab decisions board meeting', MeetingMoment::fromLocal('2026-09-01 18:00'), 'Lokalen');
    $projectorItem = $workspace->addAgendaItem($meetingA, 'Ny projektor', '§ 7');
    $planItem = $workspace->addAgendaItem($meetingA, 'Underhållsplan', '§ 8');
    $storageItem = $workspace->addAgendaItem($meetingA, 'Förrådsavtal', '§ 9');
    $a1 = $record->addDecision($meetingA, $projectorItem, 'Lab decisions buy a new projector', $anna, $yesterday);
    $a2 = $record->addDecision($meetingA, $planItem, 'Lab decisions adopt the maintenance plan', null, null);
    $a3 = $record->addDecision($meetingA, $storageItem, 'Lab decisions renew the storage agreement', $erik, $yesterday);
    $record->setFollowUp($a3, DecisionFollowUp::Done, null);
    $record->addActionItem($meetingA, $projectorItem, 'Lab decisions call the painter', $anna, $future);
    $service->markHeld($meetingA);

    $meetingB = $service->schedule($typeId, 'Lab decisions working meeting', MeetingMoment::fromLocal($today->iso() . ' 18:00'), '');
    $service->start($meetingB);
    $roofItem = $workspace->addAgendaItem($meetingB, 'Takreparation', '§ 2');
    $roof = $record->addDecision($meetingB, $roofItem, 'Lab decisions request three roof offers', null, $future);

    $meetingC = $service->schedule($typeId, 'Lab decisions level meeting', MeetingMoment::fromLocal('2026-08-15 18:00'), '');
    $level = $record->addDecision($meetingC, null, 'Lab decisions approve the meeting-level note', null, null);

    $drafts = WordpressMeetings::minutes();
    $draftId = $drafts->create($meetingA);
    $drafts->submit($draftId);
    $drafts->finalize($draftId);
    $locked = $drafts->current($meetingA);

    if ($locked === null || $locked->state()->value !== 'finalized') {
        $fail('Meeting A minutes were not finalized.');
    }

    $revisionBody = $locked->body();
    $revisionPayload = $locked->payload();
    $revisionNumber = $locked->number();
    $revisionVisibility = $locked->visibility()->value;
    $revisionId = $locked->id();
    $revisionCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$revisions} WHERE meeting_id = %d", $meetingA));
    $repository = new WpdbDecisionRepository();
    $storedA1 = $repository->find($a1);

    if ($storedA1 === null) {
        $fail('The projector decision was not stored.');
    }

    $a1Wording = $storedA1->wording();
    $a1Person = $storedA1->responsiblePersonId();
    $a1Deadline = $storedA1->deadline()?->iso();
    $a1Meeting = $storedA1->meetingId();

    $secretary = get_user_by('login', 'lab-secretary');
    $boardMember = get_user_by('login', 'lab-board-member');

    if (! $secretary instanceof WP_User || ! $boardMember instanceof WP_User) {
        $fail('Lab meeting users are missing.');
    }

    clean_user_cache((int) $secretary->ID);
    clean_user_cache((int) $boardMember->ID);
    $secretary = get_user_by('id', (int) $secretary->ID);
    $boardMember = get_user_by('id', (int) $boardMember->ID);

    if (! $secretary instanceof WP_User || ! $boardMember instanceof WP_User) {
        $fail('Lab meeting users could not be reloaded.');
    }

    if (! user_can($boardMember, Capabilities::VIEW_INTERNAL_MEETINGS) || user_can($boardMember, Capabilities::RECORD_MEETING)) {
        $fail('The board member role does not match the decisions read boundary.');
    }

    if (! function_exists('add_menu_page')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    global $submenu;

    if (! is_array($submenu) || ! isset($submenu['foreningsplugin'])) {
        Plugin::registerAdminMenu();
    }

    $decisionsCap = '';

    foreach ($submenu['foreningsplugin'] ?? [] as $item) {
        if (is_array($item) && ($item[2] ?? '') === 'foreningsplugin-decisions') {
            $decisionsCap = (string) $item[1];
        }
    }

    if ($decisionsCap !== Capabilities::VIEW_INTERNAL_MEETINGS) {
        $fail('The Decisions menu does not use view_internal_meetings.');
    }

    add_role('assoc_lab_decisions_settings', 'Lab decisions settings', [
        'read' => true,
        Capabilities::ACCESS_ASSOCIATION => true,
        Capabilities::MANAGE_ASSOCIATION => true,
    ]);
    $settingsId = wp_insert_user([
        'user_login' => 'lab-decisions-settings',
        'user_pass' => wp_generate_password(24),
        'user_email' => 'lab-decisions-settings@example.test',
        'role' => 'assoc_lab_decisions_settings',
    ]);

    if (is_wp_error($settingsId)) {
        $fail('The settings-only user could not be created.');
    }

    clean_user_cache((int) $settingsId);
    wp_set_current_user((int) $settingsId);

    if (current_user_can(Capabilities::VIEW_INTERNAL_MEETINGS)) {
        $fail('The settings-only user can view internal meetings.');
    }

    add_filter('wp_die_handler', static function () {
        return static function ($message, string $title = '', array $args = []): void {
            unset($title, $args);
            throw new RuntimeException(wp_strip_all_tags(is_string($message) ? $message : 'denied'));
        };
    });

    $denied = false;

    try {
        DecisionsPage::render();
    } catch (RuntimeException $error) {
        $denied = str_contains($error->getMessage(), 'behörighet') || str_contains($error->getMessage(), 'permission');
    }

    if (! $denied) {
        $fail('The settings-only user opened Decisions.');
    }

    ob_start();
    AssociationOverviewPage::render();
    $settingsHome = (string) ob_get_clean();

    if (str_contains($settingsHome, 'foreningsplugin-decisions') || str_contains($settingsHome, 'Lab decisions buy a new projector')) {
        $fail('The settings home exposed decisions.');
    }

    wp_set_current_user((int) $boardMember->ID);
    $viewer = $render(['page' => 'foreningsplugin-decisions']);

    if (! str_contains($viewer, 'Lab decisions buy a new projector')
        || ! str_contains($viewer, 'Lab decisions board meeting')
        || ! str_contains($viewer, '2026-09-01')
        || ! str_contains($viewer, 'Hållet')
        || ! str_contains($viewer, '§ 7 Ny projektor')
        || ! str_contains($viewer, 'Uppföljning: Öppen')
        || str_contains($viewer, 'Markera uppföljningen som klar')
        || str_contains($viewer, 'Öppna uppföljningen igen')
        || str_contains($viewer, 'assoc_set_global_decision_follow_up')
    ) {
        $fail('The read-only Decisions page is wrong.');
    }

    $_POST = [
        'action' => 'assoc_set_global_decision_follow_up',
        'decision_id' => (string) $a1,
        'follow_up' => 'done',
        '_wpnonce' => wp_create_nonce('assoc_set_global_decision_follow_up'),
    ];
    $_REQUEST = $_POST;
    $viewerDenied = false;

    try {
        DecisionsPage::setFollowUp();
    } catch (RuntimeException $error) {
        $viewerDenied = str_contains($error->getMessage(), 'behörighet') || str_contains($error->getMessage(), 'permission');
    }

    if (! $viewerDenied || $repository->find($a1)?->followUp() !== DecisionFollowUp::Open) {
        $fail('A read-only follow-up POST was accepted.');
    }

    wp_set_current_user((int) $secretary->ID);
    $openPage = $render(['page' => 'foreningsplugin-decisions']);
    $projectorAt = strpos($openPage, 'Lab decisions buy a new projector');
    $planAt = strpos($openPage, 'Lab decisions adopt the maintenance plan');
    $roofAt = strpos($openPage, 'Lab decisions request three roof offers');
    $levelAt = strpos($openPage, 'Lab decisions approve the meeting-level note');

    if ($projectorAt === false || $planAt === false || $roofAt === false || $levelAt === false || $projectorAt > $planAt || $projectorAt > $roofAt || $projectorAt > $levelAt) {
        $fail('Open follow-up is not the default order.');
    }

    if (str_contains($openPage, 'Lab decisions renew the storage agreement')
        || str_contains($openPage, 'Lab decisions call the painter')
        || ! str_contains($openPage, 'Ingen ansvarig')
        || ! str_contains($openPage, 'Inget slutdatum')
        || ! str_contains($openPage, 'Beslut på mötesnivå')
        || ! str_contains($openPage, 'Pågår')
        || ! str_contains($openPage, 'Planerat')
        || ! str_contains($openPage, 'Markera uppföljningen som klar')
        || ! str_contains($openPage, 'assoc_set_global_decision_follow_up')
        || ! str_contains($openPage, 'meeting=' . $meetingA)
        || str_contains($openPage, 'Lägg till beslut')
        || str_contains($openPage, 'Ta bort det här beslutet')
    ) {
        $fail('The default Decisions page is missing context or exposes the wrong actions.');
    }

    $donePage = $render(['page' => 'foreningsplugin-decisions', 'status' => 'done']);

    if (! str_contains($donePage, 'Lab decisions renew the storage agreement')
        || ! str_contains($donePage, 'Uppföljning: Klar')
        || ! str_contains($donePage, 'Öppna uppföljningen igen')
        || str_contains($donePage, 'Lab decisions buy a new projector')
        || str_contains($donePage, 'Lab decisions adopt the maintenance plan')
    ) {
        $fail('The done filter is wrong.');
    }

    $overduePage = $render([
        'page' => 'foreningsplugin-decisions',
        'status' => 'open',
        'urgency' => 'overdue',
    ]);

    if (! str_contains($overduePage, 'Lab decisions buy a new projector')
        || str_contains($overduePage, 'Lab decisions adopt the maintenance plan')
        || str_contains($overduePage, 'Lab decisions renew the storage agreement')
        || str_contains($overduePage, 'Lab decisions request three roof offers')
        || str_contains($overduePage, 'Lab decisions approve the meeting-level note')
    ) {
        $fail('The overdue filter is wrong.');
    }

    $responsiblePage = $render([
        'page' => 'foreningsplugin-decisions',
        'status' => 'all',
        'responsible' => (string) $anna,
    ]);

    if (! str_contains($responsiblePage, 'Lab decisions buy a new projector')
        || str_contains($responsiblePage, 'Lab decisions adopt the maintenance plan')
        || str_contains($responsiblePage, 'Lab decisions renew the storage agreement')
    ) {
        $fail('The responsible filter is wrong.');
    }

    $unassignedPage = $render([
        'page' => 'foreningsplugin-decisions',
        'status' => 'open',
        'responsible' => 'unassigned',
    ]);

    if (! str_contains($unassignedPage, 'Lab decisions adopt the maintenance plan')
        || str_contains($unassignedPage, 'Lab decisions buy a new projector')
    ) {
        $fail('The unassigned filter is wrong.');
    }

    $invalidPage = $render([
        'page' => 'foreningsplugin-decisions',
        'status' => 'deleted',
        'responsible' => 'Anna Decisions',
        'urgency' => 'soon',
    ]);

    if (! str_contains($invalidPage, 'Lab decisions buy a new projector')
        || ! str_contains($invalidPage, 'Lab decisions adopt the maintenance plan')
        || str_contains($invalidPage, 'Lab decisions renew the storage agreement')
    ) {
        $fail('Invalid filters did not fall back to open follow-up.');
    }

    $clampedSnapshot = WordpressMeetings::register()->snapshot(
        $today,
        DecisionRegisterQuery::normalize('open', null, null, 99)
    );
    $clamped = $render(['page' => 'foreningsplugin-decisions', 'paged' => '99']);

    if ($clampedSnapshot->pages < 1 || $clampedSnapshot->page !== $clampedSnapshot->pages || ! str_contains($clamped, 'Beslut antecknas i möten')) {
        $fail('A high page number was not clamped.');
    }

    if ($clampedSnapshot->pages === 1 && ! str_contains($clamped, 'Lab decisions buy a new projector')) {
        $fail('A high page number dropped the open decisions.');
    }

    $post = static function (int $decisionId, string $followUp) use ($fail): string {
        $_POST = [
            'action' => 'assoc_set_global_decision_follow_up',
            'decision_id' => (string) $decisionId,
            'follow_up' => $followUp,
            'status' => 'open',
            'responsible' => 'all',
            'urgency' => 'all',
            'paged' => '1',
            'meeting_id' => '999999',
            '_wpnonce' => wp_create_nonce('assoc_set_global_decision_follow_up'),
        ];
        $_REQUEST = $_POST;
        add_filter('wp_redirect', static function (string $location, int $status = 302): void {
            unset($status);
            throw new RuntimeException('redirect:' . $location);
        }, 1, 2);

        try {
            DecisionsPage::setFollowUp();
        } catch (RuntimeException $error) {
            remove_all_filters('wp_redirect');

            if (! str_starts_with($error->getMessage(), 'redirect:')) {
                $fail($error->getMessage());
            }

            return $error->getMessage();
        }

        remove_all_filters('wp_redirect');
        $fail('The follow-up form did not redirect.');
    };

    $doneRedirect = $post($a1, 'done');
    $updated = $repository->find($a1);

    if ($updated === null
        || ! str_contains($doneRedirect, 'assoc_notice=follow_up_done')
        || $updated->id() !== $a1
        || $updated->followUp() !== DecisionFollowUp::Done
        || $updated->wording() !== $a1Wording
        || $updated->responsiblePersonId() !== $a1Person
        || $updated->deadline()?->iso() !== $a1Deadline
        || $updated->meetingId() !== $a1Meeting
    ) {
        $fail('Marking follow-up done changed the decision record.');
    }

    $afterDone = $render(['page' => 'foreningsplugin-decisions']);
    $doneList = $render(['page' => 'foreningsplugin-decisions', 'status' => 'done']);

    if (str_contains($afterDone, 'Lab decisions buy a new projector') || ! str_contains($doneList, 'Lab decisions buy a new projector')) {
        $fail('The projector decision did not leave the open view.');
    }

    $afterRevision = WordpressMeetings::minutes()->current($meetingA);

    if ($afterRevision === null
        || $afterRevision->id() !== $revisionId
        || $afterRevision->body() !== $revisionBody
        || $afterRevision->payload() !== $revisionPayload
        || $afterRevision->number() !== $revisionNumber
        || $afterRevision->visibility()->value !== $revisionVisibility
        || (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$revisions} WHERE meeting_id = %d", $meetingA)) !== $revisionCount
    ) {
        $fail('Follow-up changed the finalized minutes.');
    }

    $openRedirect = $post($a1, 'open');
    $reopened = $repository->find($a1);

    if ($reopened === null
        || ! str_contains($openRedirect, 'assoc_notice=follow_up_open')
        || $reopened->followUp() !== DecisionFollowUp::Open
        || $reopened->wording() !== $a1Wording
    ) {
        $fail('Reopening follow-up did not restore the open decision.');
    }

    $afterReopen = WordpressMeetings::minutes()->current($meetingA);

    if ($afterReopen === null
        || $afterReopen->body() !== $revisionBody
        || $afterReopen->payload() !== $revisionPayload
        || $afterReopen->number() !== $revisionNumber
        || $afterReopen->visibility()->value !== $revisionVisibility
    ) {
        $fail('Reopening follow-up changed the finalized minutes.');
    }

    $backOnOpen = $render(['page' => 'foreningsplugin-decisions']);

    if (! str_contains($backOnOpen, 'Lab decisions buy a new projector')) {
        $fail('The reopened decision is missing from open follow-up.');
    }

    $invalidRedirect = $post($a1, 'deleted');

    if (! str_contains($invalidRedirect, 'assoc_notice=invalid') || $repository->find($a1)?->followUp() !== DecisionFollowUp::Open) {
        $fail('An invalid follow-up status was stored.');
    }

    $unknownRedirect = $post(999999, 'done');

    if (! str_contains($unknownRedirect, 'assoc_notice=invalid') || $repository->find($a1)?->followUp() !== DecisionFollowUp::Open) {
        $fail('An unknown decision changed another decision.');
    }

    $openCount = WordpressMeetings::record()->openCount();
    ob_start();
    AssociationOverviewPage::render();
    $dashboard = (string) ob_get_clean();

    if (! str_contains($dashboard, 'Öppna beslut: ' . $openCount)
        || ! str_contains($dashboard, 'foreningsplugin-decisions')
        || ! str_contains($dashboard, 'Visa beslut')
        || ! str_contains($dashboard, 'Öppna uppgifter:')
    ) {
        $fail('The dashboard decision count or link is wrong.');
    }

    if ($a2 === $a1 || $roof === $a1 || $level === $a1) {
        $fail('Decision ids collided.');
    }
} catch (RuntimeException $error) {
    $fail($error->getMessage());
} finally {
    remove_all_filters('wp_die_handler');
    remove_all_filters('wp_redirect');
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    $cleanup();
}

echo "Decisions lab passed.\n";
