<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Application\Task\TaskRegisterQuery;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\AssociationOverviewPage;
use Foreningssystem\Infrastructure\WordPress\Plugin;
use Foreningssystem\Infrastructure\WordPress\TasksPage;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;
use Foreningssystem\Infrastructure\WordPress\WpdbActionItemRepository;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$meetings = $wpdb->prefix . 'assoc_meeting';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$decisions = $wpdb->prefix . 'assoc_decision';
$actions = $wpdb->prefix . 'assoc_action_item';
$minutes = $wpdb->prefix . 'assoc_minutes';
$revisions = $wpdb->prefix . 'assoc_minutes_revision';
$titles = [
    'Lab tasks board meeting',
    'Lab tasks working meeting',
    'Lab tasks level meeting',
    'Lab tasks draft meeting',
];

$cleanup = static function () use ($wpdb, $people, $meetings, $agenda, $decisions, $actions, $minutes, $revisions, $titles): void {
    wp_set_current_user(1);

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

    foreach (['lab-tasks-anna@example.test', 'lab-tasks-erik@example.test'] as $email) {
        $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

        if ($personId) {
            lab_delete_person_memberships((int) $personId);
            $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
        }
    }

    lab_delete_membership_numbers('LAB-TASK-%');
    $settingsUser = get_user_by('login', 'lab-tasks-settings');

    if ($settingsUser instanceof WP_User) {
        wp_delete_user((int) $settingsUser->ID);
    }

    remove_role('assoc_lab_tasks_settings');
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
        TasksPage::render();
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
    $anna = $peopleService->register('Anna', 'Tasks', 'lab-tasks-anna@example.test', 'LAB-TASK-ANNA', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
    $erik = $peopleService->register('Erik', 'Tasks', 'lab-tasks-erik@example.test', 'LAB-TASK-ERIK', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
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

    $meetingA = $service->schedule($typeId, 'Lab tasks board meeting', MeetingMoment::fromLocal('2026-09-01 18:00'), 'Lokalen');
    $roofItem = $workspace->addAgendaItem($meetingA, 'Takreparationer', '§ 7');
    $projectorItem = $workspace->addAgendaItem($meetingA, 'Projektor', '§ 8');
    $insuranceItem = $workspace->addAgendaItem($meetingA, 'Försäkring', '§ 9');
    $t1 = $record->addActionItem($meetingA, $roofItem, 'Lab tasks call three roof contractors', $anna, $yesterday);
    $t2 = $record->addActionItem($meetingA, $projectorItem, 'Lab tasks order a new projector', null, null);
    $t3 = $record->addActionItem($meetingA, $insuranceItem, 'Lab tasks send insurance documents', $erik, $yesterday);
    $record->setActionStatus($t3, ActionStatus::Done, null);
    $record->addDecision($meetingA, $roofItem, 'Lab tasks adopt the roof budget', null, null);
    $service->markHeld($meetingA);

    $meetingB = $service->schedule($typeId, 'Lab tasks working meeting', MeetingMoment::fromLocal($today->iso() . ' 18:00'), '');
    $service->start($meetingB);
    $newsItem = $workspace->addAgendaItem($meetingB, 'Höstbrev', '§ 2');
    $newsletter = $record->addActionItem($meetingB, $newsItem, 'Lab tasks prepare the autumn newsletter', null, $future);

    $meetingC = $service->schedule($typeId, 'Lab tasks level meeting', MeetingMoment::fromLocal('2026-08-15 18:00'), '');
    $level = $record->addActionItem($meetingC, null, 'Lab tasks approve the meeting-level note', null, null);

    $meetingD = $service->schedule($typeId, 'Lab tasks draft meeting', MeetingMoment::fromLocal('2026-09-10 18:00'), '');
    $painterItem = $workspace->addAgendaItem($meetingD, 'Målare', '§ 1');
    $painter = $record->addActionItem($meetingD, $painterItem, 'Lab tasks call the painter', null, null);
    $service->markHeld($meetingD);

    $drafts = WordpressMeetings::minutes();
    $finalDraftId = $drafts->create($meetingA);
    $drafts->submit($finalDraftId);
    $drafts->finalize($finalDraftId);
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

    $openDraftId = $drafts->create($meetingD);
    $openDraft = $drafts->current($meetingD);

    if ($openDraft === null || $drafts->isStale($meetingD) || $openDraft->id() !== $openDraftId) {
        $fail('The open minutes draft was not fresh.');
    }

    $draftBody = $openDraft->body();
    $draftPayload = $openDraft->payload();
    $draftNumber = $openDraft->number();
    $draftRevisionId = $openDraft->id();
    $repository = new WpdbActionItemRepository();
    $storedT1 = $repository->find($t1);

    if ($storedT1 === null) {
        $fail('The roof task was not stored.');
    }

    $t1Task = $storedT1->task();
    $t1Person = $storedT1->assigneePersonId();
    $t1Due = $storedT1->dueOn()?->iso();
    $t1Meeting = $storedT1->meetingId();
    $t1Agenda = $storedT1->agendaItemId();

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
        $fail('The board member role does not match the tasks read boundary.');
    }

    if (! function_exists('add_menu_page')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    global $submenu;

    if (! is_array($submenu) || ! isset($submenu['foreningsplugin'])) {
        Plugin::registerAdminMenu();
    }

    $tasksCap = '';
    $slugs = [];

    foreach ($submenu['foreningsplugin'] ?? [] as $item) {
        if (! is_array($item)) {
            continue;
        }

        $slugs[] = (string) ($item[2] ?? '');

        if (($item[2] ?? '') === 'foreningsplugin-tasks') {
            $tasksCap = (string) $item[1];
        }
    }

    $decisionsAt = array_search('foreningsplugin-decisions', $slugs, true);
    $tasksAt = array_search('foreningsplugin-tasks', $slugs, true);
    $documentsAt = array_search('foreningsplugin-documents', $slugs, true);

    if ($tasksCap !== Capabilities::VIEW_INTERNAL_MEETINGS || $decisionsAt === false || $tasksAt === false || $documentsAt === false || $decisionsAt > $tasksAt || $tasksAt > $documentsAt) {
        $fail('The Tasks menu is missing or out of order.');
    }

    add_role('assoc_lab_tasks_settings', 'Lab tasks settings', [
        'read' => true,
        Capabilities::ACCESS_ASSOCIATION => true,
        Capabilities::MANAGE_ASSOCIATION => true,
    ]);
    $settingsId = wp_insert_user([
        'user_login' => 'lab-tasks-settings',
        'user_pass' => wp_generate_password(24),
        'user_email' => 'lab-tasks-settings@example.test',
        'role' => 'assoc_lab_tasks_settings',
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
        TasksPage::render();
    } catch (RuntimeException $error) {
        $denied = str_contains($error->getMessage(), 'behörighet') || str_contains($error->getMessage(), 'permission');
    }

    if (! $denied) {
        $fail('The settings-only user opened Tasks.');
    }

    ob_start();
    AssociationOverviewPage::render();
    $settingsHome = (string) ob_get_clean();

    if (str_contains($settingsHome, 'foreningsplugin-tasks') || str_contains($settingsHome, 'Lab tasks call three roof contractors')) {
        $fail('The settings home exposed tasks.');
    }

    wp_set_current_user((int) $boardMember->ID);
    $viewer = $render(['page' => 'foreningsplugin-tasks']);

    if (! str_contains($viewer, 'Lab tasks call three roof contractors')
        || ! str_contains($viewer, 'Lab tasks board meeting')
        || ! str_contains($viewer, '2026-09-01')
        || ! str_contains($viewer, 'Hållet')
        || ! str_contains($viewer, '§ 7 Takreparationer')
        || ! str_contains($viewer, 'Status: Öppen')
        || str_contains($viewer, 'Markera uppgiften som klar')
        || str_contains($viewer, 'Öppna uppgiften igen')
        || str_contains($viewer, 'assoc_set_global_task_status')
        || str_contains($viewer, 'Lab tasks adopt the roof budget')
    ) {
        $fail('The read-only Tasks page is wrong.');
    }

    $_POST = [
        'action' => 'assoc_set_global_task_status',
        'action_item_id' => (string) $t1,
        'status' => 'done',
        'meeting_id' => '999999',
        '_wpnonce' => wp_create_nonce('assoc_set_global_task_status'),
    ];
    $_REQUEST = $_POST;
    $viewerDenied = false;

    try {
        TasksPage::setStatus();
    } catch (RuntimeException $error) {
        $viewerDenied = str_contains($error->getMessage(), 'behörighet') || str_contains($error->getMessage(), 'permission');
    }

    if (! $viewerDenied || $repository->find($t1)?->status() !== ActionStatus::Open) {
        $fail('A read-only task POST was accepted.');
    }

    wp_set_current_user((int) $secretary->ID);
    $openPage = $render(['page' => 'foreningsplugin-tasks']);
    $roofAt = strpos($openPage, 'Lab tasks call three roof contractors');
    $projectorAt = strpos($openPage, 'Lab tasks order a new projector');
    $newsAt = strpos($openPage, 'Lab tasks prepare the autumn newsletter');
    $levelAt = strpos($openPage, 'Lab tasks approve the meeting-level note');

    if ($roofAt === false || $projectorAt === false || $newsAt === false || $levelAt === false || $roofAt > $projectorAt || $roofAt > $newsAt || $roofAt > $levelAt) {
        $fail('Open tasks are not the default order.');
    }

    if (str_contains($openPage, 'Lab tasks send insurance documents')
        || str_contains($openPage, 'Lab tasks adopt the roof budget')
        || ! str_contains($openPage, 'Ingen ansvarig')
        || ! str_contains($openPage, 'Inget förfallodatum')
        || ! str_contains($openPage, 'Uppgift på mötesnivå')
        || ! str_contains($openPage, 'Pågår')
        || ! str_contains($openPage, 'Planerat')
        || ! str_contains($openPage, 'Markera uppgiften som klar')
        || ! str_contains($openPage, 'assoc_set_global_task_status')
        || ! str_contains($openPage, 'meeting=' . $meetingA)
        || str_contains($openPage, 'Lägg till uppgift')
        || str_contains($openPage, 'Ta bort den här uppgiften')
    ) {
        $fail('The default Tasks page is missing context or exposes the wrong actions.');
    }

    $donePage = $render(['page' => 'foreningsplugin-tasks', 'status' => 'done']);

    if (! str_contains($donePage, 'Lab tasks send insurance documents')
        || ! str_contains($donePage, 'Status: Klar')
        || ! str_contains($donePage, 'Öppna uppgiften igen')
        || str_contains($donePage, 'Lab tasks call three roof contractors')
        || str_contains($donePage, 'Lab tasks order a new projector')
    ) {
        $fail('The done filter is wrong.');
    }

    $overduePage = $render([
        'page' => 'foreningsplugin-tasks',
        'status' => 'open',
        'urgency' => 'overdue',
    ]);

    if (! str_contains($overduePage, 'Lab tasks call three roof contractors')
        || str_contains($overduePage, 'Lab tasks order a new projector')
        || str_contains($overduePage, 'Lab tasks send insurance documents')
        || str_contains($overduePage, 'Lab tasks prepare the autumn newsletter')
        || str_contains($overduePage, 'Lab tasks approve the meeting-level note')
        || str_contains($overduePage, 'Lab tasks call the painter')
    ) {
        $fail('The overdue filter is wrong.');
    }

    $assigneePage = $render([
        'page' => 'foreningsplugin-tasks',
        'status' => 'all',
        'assignee' => (string) $anna,
    ]);

    if (! str_contains($assigneePage, 'Lab tasks call three roof contractors')
        || str_contains($assigneePage, 'Lab tasks order a new projector')
        || str_contains($assigneePage, 'Lab tasks send insurance documents')
    ) {
        $fail('The assignee filter is wrong.');
    }

    $unassignedPage = $render([
        'page' => 'foreningsplugin-tasks',
        'status' => 'open',
        'assignee' => 'unassigned',
    ]);

    if (! str_contains($unassignedPage, 'Lab tasks order a new projector')
        || str_contains($unassignedPage, 'Lab tasks call three roof contractors')
    ) {
        $fail('The unassigned filter is wrong.');
    }

    $invalidPage = $render([
        'page' => 'foreningsplugin-tasks',
        'status' => 'deleted',
        'assignee' => 'Anna Tasks',
        'urgency' => 'soon',
    ]);

    if (! str_contains($invalidPage, 'Lab tasks call three roof contractors')
        || ! str_contains($invalidPage, 'Lab tasks order a new projector')
        || str_contains($invalidPage, 'Lab tasks send insurance documents')
    ) {
        $fail('Invalid filters did not fall back to open tasks.');
    }

    $clampedSnapshot = WordpressMeetings::tasks()->snapshot(
        $today,
        TaskRegisterQuery::normalize('open', null, null, 99)
    );
    $clamped = $render(['page' => 'foreningsplugin-tasks', 'paged' => '99']);

    if ($clampedSnapshot->pages < 1 || $clampedSnapshot->page !== $clampedSnapshot->pages || ! str_contains($clamped, 'Uppgifter antecknas i möten')) {
        $fail('A high page number was not clamped.');
    }

    if ($clampedSnapshot->pages === 1 && ! str_contains($clamped, 'Lab tasks call three roof contractors')) {
        $fail('A high page number dropped the open tasks.');
    }

    $post = static function (int $actionItemId, string $status) use ($fail): string {
        $_POST = [
            'action' => 'assoc_set_global_task_status',
            'action_item_id' => (string) $actionItemId,
            'status' => $status,
            'status_filter' => 'open',
            'assignee' => 'all',
            'urgency' => 'all',
            'paged' => '1',
            'meeting_id' => '999999',
            '_wpnonce' => wp_create_nonce('assoc_set_global_task_status'),
        ];
        $_REQUEST = $_POST;
        add_filter('wp_redirect', static function (string $location, int $code = 302): void {
            unset($code);
            throw new RuntimeException('redirect:' . $location);
        }, 1, 2);

        try {
            TasksPage::setStatus();
        } catch (RuntimeException $error) {
            remove_all_filters('wp_redirect');

            if (! str_starts_with($error->getMessage(), 'redirect:')) {
                $fail($error->getMessage());
            }

            return $error->getMessage();
        }

        remove_all_filters('wp_redirect');
        $fail('The task form did not redirect.');
    };

    $doneRedirect = $post($t1, 'done');
    $updated = $repository->find($t1);

    if ($updated === null
        || ! str_contains($doneRedirect, 'assoc_notice=task_done')
        || $updated->id() !== $t1
        || $updated->status() !== ActionStatus::Done
        || $updated->task() !== $t1Task
        || $updated->assigneePersonId() !== $t1Person
        || $updated->dueOn()?->iso() !== $t1Due
        || $updated->meetingId() !== $t1Meeting
        || $updated->agendaItemId() !== $t1Agenda
    ) {
        $fail('Marking the task done changed more than its status.');
    }

    $afterDone = $render(['page' => 'foreningsplugin-tasks']);
    $doneList = $render(['page' => 'foreningsplugin-tasks', 'status' => 'done']);

    if (str_contains($afterDone, 'Lab tasks call three roof contractors') || ! str_contains($doneList, 'Lab tasks call three roof contractors')) {
        $fail('The roof task did not leave the open view.');
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
        $fail('Task status changed the finalized minutes.');
    }

    $openRedirect = $post($t1, 'open');
    $reopened = $repository->find($t1);

    if ($reopened === null
        || ! str_contains($openRedirect, 'assoc_notice=task_open')
        || $reopened->status() !== ActionStatus::Open
        || $reopened->task() !== $t1Task
        || $reopened->meetingId() !== $t1Meeting
    ) {
        $fail('Reopening the task did not restore it.');
    }

    $afterReopen = WordpressMeetings::minutes()->current($meetingA);

    if ($afterReopen === null
        || $afterReopen->body() !== $revisionBody
        || $afterReopen->payload() !== $revisionPayload
        || $afterReopen->number() !== $revisionNumber
        || $afterReopen->visibility()->value !== $revisionVisibility
        || (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$revisions} WHERE meeting_id = %d", $meetingA)) !== $revisionCount
    ) {
        $fail('Reopening the task changed the finalized minutes.');
    }

    $backOnOpen = $render(['page' => 'foreningsplugin-tasks']);

    if (! str_contains($backOnOpen, 'Lab tasks call three roof contractors')) {
        $fail('The reopened task is missing from open tasks.');
    }

    $painterRedirect = $post($painter, 'done');
    $painterAfter = $repository->find($painter);
    $draftAfter = WordpressMeetings::minutes()->current($meetingD);

    if ($painterAfter === null
        || ! str_contains($painterRedirect, 'assoc_notice=task_done')
        || $painterAfter->status() !== ActionStatus::Done
        || $painterAfter->task() !== 'Lab tasks call the painter'
        || $draftAfter === null
        || $draftAfter->id() !== $draftRevisionId
        || $draftAfter->body() !== $draftBody
        || $draftAfter->payload() !== $draftPayload
        || $draftAfter->number() !== $draftNumber
        || ! WordpressMeetings::minutes()->isStale($meetingD)
    ) {
        $fail('The open draft was rewritten or was not marked stale.');
    }

    $invalidRedirect = $post($t1, 'deleted');

    if (! str_contains($invalidRedirect, 'assoc_notice=invalid') || $repository->find($t1)?->status() !== ActionStatus::Open) {
        $fail('An invalid task status was stored.');
    }

    $unknownRedirect = $post(999999, 'done');

    if (! str_contains($unknownRedirect, 'assoc_notice=invalid') || $repository->find($t1)?->status() !== ActionStatus::Open) {
        $fail('An unknown task changed another task.');
    }

    $openCount = WordpressMeetings::record()->openActionCount();
    ob_start();
    AssociationOverviewPage::render();
    $dashboard = (string) ob_get_clean();

    if (! str_contains($dashboard, 'Öppna uppgifter: ' . $openCount)
        || ! str_contains($dashboard, 'foreningsplugin-tasks')
        || ! str_contains($dashboard, 'Visa uppgifter')
        || ! str_contains($dashboard, 'Försenade uppgifter')
        || ! str_contains($dashboard, 'Öppna beslut:')
    ) {
        $fail('The dashboard task count or link is wrong.');
    }

    if ($t2 === $t1 || $newsletter === $t1 || $level === $t1 || $painter === $t1) {
        $fail('Task ids collided.');
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

echo "Tasks lab passed.\n";
