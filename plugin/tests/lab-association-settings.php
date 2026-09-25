<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\Settings\StructureRuleException;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Infrastructure\WordPress\AssociationOverviewPage;
use Foreningssystem\Infrastructure\WordPress\AssociationProfilePage;
use Foreningssystem\Infrastructure\WordPress\DocumentsPage;
use Foreningssystem\Infrastructure\WordPress\MembersPage;
use Foreningssystem\Infrastructure\WordPress\MinutesLockPage;
use Foreningssystem\Infrastructure\WordPress\MinutesPublishPage;
use Foreningssystem\Infrastructure\WordPress\Plugin;
use Foreningssystem\Infrastructure\WordPress\RetentionPage;
use Foreningssystem\Infrastructure\WordPress\WordpressAccess;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Infrastructure\WordPress\AssociationSettingsPage;
use Foreningssystem\Infrastructure\WordPress\BoardPage;
use Foreningssystem\Infrastructure\WordPress\CurrentBoardBlock;
use Foreningssystem\Infrastructure\WordPress\MeetingsPage;
use Foreningssystem\Infrastructure\WordPress\WordpressAssociationSettings;
use Foreningssystem\Infrastructure\WordPress\WordpressBoard;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$assignments = $wpdb->prefix . 'assoc_board_assignment';
$roles = $wpdb->prefix . 'assoc_board_role';
$types = $wpdb->prefix . 'assoc_meeting_type';
$meetings = $wpdb->prefix . 'assoc_meeting';
$participants = $wpdb->prefix . 'assoc_meeting_participant';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$templates = $wpdb->prefix . 'assoc_meeting_template';
$templateItems = $wpdb->prefix . 'assoc_meeting_template_item';
$email = 'lab-settings-nora@example.test';
$roleSlugs = ['custom_materialansvarig'];
$typeSlugs = ['custom_budgetmote', 'custom_projektmote'];
$meetingTitles = ['Lab settings budget', 'Lab settings board'];
$templateName = 'Lab settings template';
$roleOrder = [];
$typeOrder = [];

$capture = static function (callable $render): string {
    ob_start();
    $render();

    return (string) ob_get_clean();
};

$cleanup = static function () use (
    $wpdb,
    $people,
    $assignments,
    $roles,
    $types,
    $meetings,
    $participants,
    $agenda,
    $templates,
    $templateItems,
    $email,
    $roleSlugs,
    $typeSlugs,
    $meetingTitles,
    $templateName,
    &$roleOrder,
    &$typeOrder
): void {
    wp_set_current_user(1);
    $personId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

    if ($personId > 0) {
        $wpdb->delete($assignments, ['person_id' => $personId], ['%d']);
        lab_delete_person_memberships($personId);
        $wpdb->delete($people, ['id' => $personId], ['%d']);
    }

    foreach ($meetingTitles as $title) {
        $meetingId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$meetings} WHERE title = %s", $title));

        if ($meetingId < 1) {
            continue;
        }

        $wpdb->delete($participants, ['meeting_id' => $meetingId], ['%d']);
        $wpdb->delete($agenda, ['meeting_id' => $meetingId], ['%d']);
        $wpdb->delete($meetings, ['id' => $meetingId], ['%d']);
    }

    $templateId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$templates} WHERE name = %s", $templateName));

    if ($templateId > 0) {
        $wpdb->delete($templateItems, ['template_id' => $templateId], ['%d']);
        $wpdb->delete($templates, ['id' => $templateId], ['%d']);
    }

    foreach ($roleSlugs as $slug) {
        $roleId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", $slug));

        if ($roleId < 1) {
            continue;
        }

        $wpdb->delete($assignments, ['role_id' => $roleId], ['%d']);
        $wpdb->delete($roles, ['id' => $roleId], ['%d']);
    }

    foreach ($typeSlugs as $slug) {
        $typeId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$types} WHERE slug = %s", $slug));

        if ($typeId < 1) {
            continue;
        }

        $templateIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$templates} WHERE type_id = %d", $typeId));

        if (is_array($templateIds)) {
            foreach ($templateIds as $id) {
                $wpdb->delete($templateItems, ['template_id' => (int) $id], ['%d']);
                $wpdb->delete($templates, ['id' => (int) $id], ['%d']);
            }
        }

        $meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE type_id = %d", $typeId));

        if (is_array($meetingIds)) {
            foreach ($meetingIds as $id) {
                $wpdb->delete($participants, ['meeting_id' => (int) $id], ['%d']);
                $wpdb->delete($agenda, ['meeting_id' => (int) $id], ['%d']);
                $wpdb->delete($meetings, ['id' => (int) $id], ['%d']);
            }
        }

        $wpdb->delete($types, ['id' => $typeId], ['%d']);
    }

    foreach ($roleOrder as $id => $sortOrder) {
        $wpdb->update($roles, ['sort_order' => $sortOrder], ['id' => $id], ['%d'], ['%d']);
    }

    foreach ($typeOrder as $id => $sortOrder) {
        $wpdb->update($types, ['sort_order' => $sortOrder], ['id' => $id], ['%d'], ['%d']);
    }
};

$snapshot = static function (string $table) use ($wpdb): array {
    $rows = $wpdb->get_results("SELECT id, sort_order FROM {$table}", ARRAY_A);
    $order = [];

    if (! is_array($rows)) {
        return $order;
    }

    foreach ($rows as $row) {
        if (is_array($row)) {
            $order[(int) $row['id']] = (int) $row['sort_order'];
        }
    }

    return $order;
};

try {
    $cleanup();
    $roleOrder = $snapshot($roles);
    $typeOrder = $snapshot($types);
    $secretary = get_user_by('login', 'lab-secretary');

    if (! $secretary instanceof WP_User) {
        \WP_CLI::error('lab-secretary is missing.');
    }

    wp_set_current_user((int) $secretary->ID);

    if (current_user_can(Capabilities::MANAGE_ASSOCIATION) || ! current_user_can(Capabilities::MANAGE_MEETINGS)) {
        \WP_CLI::error('lab-secretary does not have the expected capabilities.');
    }

    $denied = false;

    try {
        WordpressAssociationSettings::boardRoles()->create('Materialansvarig', false);
    } catch (NotAllowed) {
        $denied = true;
    }

    if (! $denied || $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", 'custom_materialansvarig'))) {
        \WP_CLI::error('A user without manage_association changed a board role.');
    }

    wp_set_current_user(1);

    if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
        \WP_CLI::error('The administrator cannot manage association settings.');
    }

    $_GET = [];
    $settings = $capture(static function (): void {
        AssociationSettingsPage::render();
    });

    foreach ([
        'Föreningsinställningar',
        'Föreningsprofil',
        'Styrelseroller',
        'Mötestyper',
        'Låsa protokoll',
        'Publicera protokoll',
        'Gallring',
        'Öppna föreningsguiden igen',
        'assoc-settings-card-link',
        'section=profile',
        'section=board-roles',
        'section=meeting-types',
        'section=minutes-lock',
        'section=minutes-publish',
        'section=retention',
        'page=foreningsplugin-setup',
    ] as $needle) {
        if (! str_contains($settings, $needle)) {
            \WP_CLI::error('Settings hub did not show: ' . $needle);
        }
    }

    if (str_contains($settings, 'onclick=') || ! str_contains($settings, '<a class="assoc-settings-card-link"')) {
        \WP_CLI::error('Settings hub cards are not semantic links.');
    }

    foreach (['Flytta Ordförande uppåt', 'assoc_add_board_role', 'assoc_add_meeting_type'] as $needle) {
        if (str_contains($settings, $needle)) {
            \WP_CLI::error('Settings hub dumped a focused settings form: ' . $needle);
        }
    }

    if (str_contains($settings, 'name="slug"')) {
        \WP_CLI::error('Settings asked for an internal slug.');
    }

    $_GET['section'] = 'board-roles';
    $boardRoles = $capture(static function (): void {
        AssociationSettingsPage::render();
    });
    unset($_GET['section']);

    foreach ([
        'Styrelseroller',
        'Tillbaka till inställningar',
        'Flytta Ordförande uppåt',
        'Flytta Ordförande nedåt',
        'assoc_add_board_role',
    ] as $needle) {
        if (! str_contains($boardRoles, $needle)) {
            \WP_CLI::error('Board roles settings did not show: ' . $needle);
        }
    }

    $_GET['section'] = 'meeting-types';
    $meetingTypes = $capture(static function (): void {
        AssociationSettingsPage::render();
    });
    unset($_GET['section']);

    foreach ([
        'Mötestyper',
        'Tillbaka till inställningar',
        'assoc_add_meeting_type',
    ] as $needle) {
        if (! str_contains($meetingTypes, $needle)) {
            \WP_CLI::error('Meeting types settings did not show: ' . $needle);
        }
    }

    $role = WordpressAssociationSettings::boardRoles()->create('Materialansvarig', false);

    if ($role->slug() !== 'custom_materialansvarig' || $role->allowsMultiple()) {
        \WP_CLI::error('Materialansvarig did not get a single-holder custom slug.');
    }

    $_GET['section'] = 'board-roles';
    $settings = $capture(static function (): void {
        AssociationSettingsPage::render();
    });
    unset($_GET['section']);

    if (! str_contains($settings, 'Materialansvarig') || ! str_contains($settings, 'Redigera Materialansvarig') || str_contains($settings, 'custom_materialansvarig')) {
        \WP_CLI::error('The new board role was not shown as association content.');
    }

    $board = $capture(static function (): void {
        BoardPage::render();
    });

    foreach (['Aktuell styrelse', 'assoc-board-current', 'Visa historik', 'assoc-board-history-link'] as $needle) {
        if (! str_contains($board, $needle)) {
            \WP_CLI::error('Board administration did not show: ' . $needle);
        }
    }

    $_GET['assoc_board_step'] = 'role';
    $_GET['assoc_board_task'] = 'add';
    $addRoles = $capture(static function (): void {
        BoardPage::render();
    });
    unset($_GET['assoc_board_step'], $_GET['assoc_board_task']);

    if (! str_contains($addRoles, 'Materialansvarig')) {
        \WP_CLI::error('Board wizard add-holder step did not list Materialansvarig.');
    }

    $_GET['assoc_view'] = 'history';
    $history = $capture(static function (): void {
        BoardPage::render();
    });
    unset($_GET['assoc_view']);

    foreach (['assoc-board-history', 'Historik'] as $needle) {
        if (! str_contains($history, $needle)) {
            \WP_CLI::error('Board history view did not show: ' . $needle);
        }
    }

    $today = AssociationDate::fromIso(wp_date('Y-m-d'));
    $personId = WordpressPeople::service()->register('Nora', 'Settingslab', $email, 'LAB-SETTINGS-1', 'ordinarie', $today);
    WordpressBoard::service()->place($personId, (int) $role->id(), $today, null, 'material@example.test', '');
    $board = $capture(static function (): void {
        BoardPage::render();
    });

    if (! str_contains($board, 'Nora Settingslab') || ! str_contains($board, 'Materialansvarig')) {
        \WP_CLI::error('The assignment was not shown under the custom role.');
    }

    $public = CurrentBoardBlock::render();

    if (! str_contains($public, 'Nora Settingslab') || ! str_contains($public, 'Materialansvarig') || str_contains($public, 'custom_materialansvarig')) {
        \WP_CLI::error('The public board did not keep the custom role name private from its slug.');
    }

    $_GET = [
        'section' => 'board-roles',
        'edit_role' => (string) $role->id(),
    ];
    $locked = $capture(static function (): void {
        AssociationSettingsPage::render();
    });
    $_GET = [];

    if (! str_contains($locked, 'Den här rollen har använts i ett styrelseuppdrag') || str_contains($locked, 'name="role_name"')) {
        \WP_CLI::error('A used board role was still presented as editable.');
    }

    $before = $wpdb->get_row($wpdb->prepare("SELECT name, allows_multiple, slug, sort_order FROM {$roles} WHERE id = %d", (int) $role->id()), ARRAY_A);
    $renameRejected = false;

    try {
        WordpressAssociationSettings::boardRoles()->rename((int) $role->id(), 'Fastighetsansvarig');
    } catch (StructureRuleException $error) {
        $renameRejected = $error->rule() === StructureRuleException::USED;
    }

    $holdersRejected = false;

    try {
        WordpressAssociationSettings::boardRoles()->changeHolders((int) $role->id(), true);
    } catch (StructureRuleException $error) {
        $holdersRejected = $error->rule() === StructureRuleException::USED;
    }

    $after = $wpdb->get_row($wpdb->prepare("SELECT name, allows_multiple, slug, sort_order FROM {$roles} WHERE id = %d", (int) $role->id()), ARRAY_A);

    if (! $renameRejected || ! $holdersRejected || $before !== $after) {
        \WP_CLI::error('A used board role changed after a rejected edit.');
    }

    WordpressAssociationSettings::boardRoles()->move((int) $role->id(), 'up');
    $moved = (int) $wpdb->get_var($wpdb->prepare("SELECT sort_order FROM {$roles} WHERE id = %d", (int) $role->id()));

    if ($moved === (int) $before['sort_order'] || $wpdb->get_var($wpdb->prepare("SELECT name FROM {$roles} WHERE id = %d", (int) $role->id())) !== 'Materialansvarig') {
        \WP_CLI::error('A used board role could not be reordered.');
    }

    $chairId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", 'chair'));
    $chairBefore = $wpdb->get_row($wpdb->prepare("SELECT slug, name, allows_multiple FROM {$roles} WHERE id = %d", $chairId), ARRAY_A);
    $chairRejected = false;

    try {
        WordpressAssociationSettings::boardRoles()->rename($chairId, 'Supreme Leader');
    } catch (StructureRuleException $error) {
        $chairRejected = $error->rule() === StructureRuleException::BUILTIN;
    }

    try {
        WordpressAssociationSettings::boardRoles()->changeHolders($chairId, true);
    } catch (StructureRuleException $error) {
        $chairRejected = $chairRejected && $error->rule() === StructureRuleException::BUILTIN;
    }

    $chairAfter = $wpdb->get_row($wpdb->prepare("SELECT slug, name, allows_multiple FROM {$roles} WHERE id = %d", $chairId), ARRAY_A);

    if (! $chairRejected || $chairBefore !== $chairAfter || $chairAfter['name'] !== 'Ordförande' || (int) $chairAfter['allows_multiple'] !== 0) {
        \WP_CLI::error('The built-in chair role was changed.');
    }

    $type = WordpressAssociationSettings::meetingTypes()->create('Budgetmöte');

    if ($type->slug() !== 'custom_budgetmote') {
        \WP_CLI::error('Budgetmöte did not get a safe custom slug.');
    }

    $_GET['section'] = 'meeting-types';
    $settings = $capture(static function (): void {
        AssociationSettingsPage::render();
    });
    unset($_GET['section']);
    $meetingList = $capture(static function (): void {
        MeetingsPage::render();
    });

    if (! str_contains($settings, 'Budgetmöte') || ! str_contains($meetingList, 'Budgetmöte') || str_contains($settings, 'custom_budgetmote') || ! str_contains($meetingList, 'Styrelsemöte') || ! str_contains($meetingList, 'Ingen mall')) {
        \WP_CLI::error('The custom meeting type did not appear beside the built-in types.');
    }

    $budgetMeeting = WordpressMeetings::service()->schedule(
        (int) $type->id(),
        'Lab settings budget',
        MeetingMoment::fromLocal('2026-10-20 18:00:00'),
        'Lab hall'
    );
    $boardTypeId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$types} WHERE slug = %s", 'board_meeting'));
    WordpressMeetings::service()->schedule(
        $boardTypeId,
        'Lab settings board',
        MeetingMoment::fromLocal('2026-10-21 18:00:00'),
        'Lab hall'
    );
    $meetingList = $capture(static function (): void {
        MeetingsPage::render();
    });
    $_GET = ['meeting' => (string) $budgetMeeting];
    $workspace = $capture(static function (): void {
        MeetingsPage::render();
    });
    $_GET = [];

    if (! str_contains($meetingList, 'Lab settings budget') || ! str_contains($meetingList, 'Budgetmöte') || ! str_contains($meetingList, 'Lab settings board') || ! str_contains($meetingList, 'Styrelsemöte') || ! str_contains($workspace, 'Lab settings budget')) {
        \WP_CLI::error('Scheduling did not resolve the custom or built-in meeting type.');
    }

    $typeBefore = $wpdb->get_row($wpdb->prepare("SELECT name, slug FROM {$types} WHERE id = %d", (int) $type->id()), ARRAY_A);
    $typeRenameRejected = false;

    try {
        WordpressAssociationSettings::meetingTypes()->rename((int) $type->id(), 'Ekonomimöte');
    } catch (StructureRuleException $error) {
        $typeRenameRejected = $error->rule() === StructureRuleException::USED;
    }

    $typeAfter = $wpdb->get_row($wpdb->prepare("SELECT name, slug FROM {$types} WHERE id = %d", (int) $type->id()), ARRAY_A);
    $typeSort = (int) $wpdb->get_var($wpdb->prepare("SELECT sort_order FROM {$types} WHERE id = %d", (int) $type->id()));
    WordpressAssociationSettings::meetingTypes()->move((int) $type->id(), 'up');
    $typeMoved = (int) $wpdb->get_var($wpdb->prepare("SELECT sort_order FROM {$types} WHERE id = %d", (int) $type->id()));

    if (! $typeRenameRejected || $typeBefore !== $typeAfter || $typeMoved === $typeSort) {
        \WP_CLI::error('A used meeting type did not stay historically locked.');
    }

    $project = WordpressAssociationSettings::meetingTypes()->create('Projektmöte');

    if ($project->slug() !== 'custom_projektmote') {
        \WP_CLI::error('Projektmöte did not get a safe custom slug.');
    }

    WordpressMeetings::templates()->create((int) $project->id(), $templateName);
    $projectMeetings = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$meetings} WHERE type_id = %d", (int) $project->id()));
    $projectRejected = false;

    try {
        WordpressAssociationSettings::meetingTypes()->rename((int) $project->id(), 'Arbetsgrupp');
    } catch (StructureRuleException $error) {
        $projectRejected = $error->rule() === StructureRuleException::USED;
    }

    $projectName = (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$types} WHERE id = %d", (int) $project->id()));
    $meetingList = $capture(static function (): void {
        MeetingsPage::render();
    });

    if ($projectMeetings !== 0 || ! $projectRejected || $projectName !== 'Projektmöte' || ! str_contains($meetingList, $templateName)) {
        \WP_CLI::error('A meeting type used only by a template could be renamed.');
    }
} finally {
    $_GET = [];
    $cleanup();
}

$navigationRoles = ['assoc_lab_settings', 'assoc_lab_menu'];
$navigationUsers = ['lab-settings-only', 'lab-menu-only'];
$bundleSnapshot = get_option(WordpressAccess::OPTION, null);
$dieAsException = static function () {
    return static function ($message): void {
        $text = is_string($message) ? $message : 'denied';

        throw new \RuntimeException(wp_strip_all_tags($text));
    };
};

try {
    $stored = is_array($bundleSnapshot) ? $bundleSnapshot : [];
    $stored[RoleBundles::SECRETARY] = [
        Capabilities::VIEW_INTERNAL_MEETINGS,
        Capabilities::MANAGE_MEETINGS,
        Capabilities::RECORD_MEETING,
        Capabilities::MANAGE_DOCUMENTS,
        Capabilities::VIEW_BOARD_DOCUMENTS,
        Capabilities::VIEW_MEMBERS,
    ];
    update_option(WordpressAccess::OPTION, $stored);
    $loaded = WordpressAccess::load();
    WordpressAccess::sync();
    WordpressAccess::sync();
    $secretary = get_user_by('login', 'lab-secretary');

    if (! $secretary instanceof WP_User) {
        \WP_CLI::error('lab-secretary is missing.');
    }

    clean_user_cache((int) $secretary->ID);
    $secretary = get_user_by('id', (int) $secretary->ID);

    if (! $secretary instanceof WP_User
        || ! user_can($secretary, Capabilities::ACCESS_ASSOCIATION)
        || ! user_can($secretary, Capabilities::VIEW_MEMBERS)
        || ! user_can($secretary, Capabilities::MANAGE_MEETINGS)
        || ! user_can($secretary, Capabilities::VIEW_INTERNAL_MEETINGS)
        || user_can($secretary, Capabilities::MANAGE_ASSOCIATION)
        || ! in_array(Capabilities::ACCESS_ASSOCIATION, $loaded->capabilitiesFor(RoleBundles::SECRETARY), true)
    ) {
        \WP_CLI::error('An older secretary bundle did not keep its access and gain only menu navigation.');
    }

    if (! function_exists('add_menu_page')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    global $menu, $submenu;

    if (! is_array($menu)) {
        $menu = [];
    }

    $parentCap = '';

    if (is_array($menu)) {
        foreach ($menu as $item) {
            if (is_array($item) && ($item[2] ?? '') === 'foreningsplugin') {
                $parentCap = (string) $item[1];
            }
        }
    }

    if ($parentCap === '') {
        Plugin::registerAdminMenu();

        foreach ($menu as $item) {
            if (is_array($item) && ($item[2] ?? '') === 'foreningsplugin') {
                $parentCap = (string) $item[1];
            }
        }
    }

    $settingsCap = '';
    $membersCap = '';
    $visibleSettingsSlugs = [];

    foreach ($submenu['foreningsplugin'] ?? [] as $item) {
        if (! is_array($item)) {
            continue;
        }

        $slug = (string) ($item[2] ?? '');
        $visibleSettingsSlugs[] = $slug;

        if ($slug === 'foreningsplugin-settings') {
            $settingsCap = (string) $item[1];
        }

        if ($slug === 'foreningsplugin-members') {
            $membersCap = (string) $item[1];
        }
    }

    if ($parentCap !== Capabilities::ACCESS_ASSOCIATION || $settingsCap !== Capabilities::MANAGE_ASSOCIATION || $membersCap !== Capabilities::VIEW_MEMBERS) {
        \WP_CLI::error('The Association menu capabilities are not separated.');
    }

    foreach (['foreningsplugin-profile', 'foreningsplugin-retention', 'foreningsplugin-minutes-lock', 'foreningsplugin-minutes-publish'] as $hidden) {
        if (in_array($hidden, $visibleSettingsSlugs, true)) {
            \WP_CLI::error('A settings sub-screen still appears as its own Association menu item: ' . $hidden);
        }
    }

    $_GET['section'] = 'profile';
    $settingsProfile = $capture(static function (): void {
        AssociationSettingsPage::render();
    });
    unset($_GET['section']);

    if (! str_contains($settingsProfile, 'Profil') || ! str_contains($settingsProfile, 'Tillbaka till inställningar')) {
        \WP_CLI::error('Settings section=profile did not open the profile screen.');
    }

    foreach ($navigationRoles as $role) {
        remove_role($role);
    }

    add_role('assoc_lab_settings', 'Lab settings only', [
        'read' => true,
        Capabilities::ACCESS_ASSOCIATION => true,
        Capabilities::MANAGE_ASSOCIATION => true,
    ]);
    add_role('assoc_lab_menu', 'Lab menu only', [
        'read' => true,
        Capabilities::ACCESS_ASSOCIATION => true,
    ]);

    $settingsUser = wp_insert_user([
        'user_login' => 'lab-settings-only',
        'user_pass' => wp_generate_password(24),
        'user_email' => 'lab-settings-only@example.test',
        'role' => 'assoc_lab_settings',
    ]);
    $menuUser = wp_insert_user([
        'user_login' => 'lab-menu-only',
        'user_pass' => wp_generate_password(24),
        'user_email' => 'lab-menu-only@example.test',
        'role' => 'assoc_lab_menu',
    ]);

    if (is_wp_error($settingsUser) || is_wp_error($menuUser)) {
        \WP_CLI::error('The navigation lab users could not be created.');
    }

    clean_user_cache((int) $settingsUser);
    $settingsUser = get_user_by('id', (int) $settingsUser);
    wp_set_current_user((int) $settingsUser->ID);

    if (! current_user_can(Capabilities::ACCESS_ASSOCIATION)
        || ! current_user_can(Capabilities::MANAGE_ASSOCIATION)
        || current_user_can(Capabilities::VIEW_MEMBERS)
        || current_user_can(Capabilities::MANAGE_BOARD)
        || current_user_can(Capabilities::VIEW_INTERNAL_MEETINGS)
        || current_user_can(Capabilities::VIEW_BOARD_DOCUMENTS)
    ) {
        \WP_CLI::error('The settings-only user has the wrong capabilities.');
    }

    if (! user_can(1, Capabilities::ACCESS_ASSOCIATION) || ! user_can(1, Capabilities::MANAGE_ASSOCIATION)) {
        \WP_CLI::error('The administrator lost association capabilities.');
    }

    $home = $capture(static function (): void {
        AssociationOverviewPage::render();
    });

    if (! str_contains($home, 'Du har tillgång till föreningsinställningar')
        || ! str_contains($home, 'page=foreningsplugin-settings')
        || str_contains($home, 'Behöver uppmärksamhet')
        || str_contains($home, 'I korthet')
    ) {
        \WP_CLI::error('The association home exposed operational data to a settings-only user.');
    }

    $settingsPage = $capture(static function (): void {
        AssociationSettingsPage::render();
    });
    $profilePage = $capture(static function (): void {
        AssociationProfilePage::render();
    });
    $retentionPage = $capture(static function (): void {
        RetentionPage::render();
    });
    $lockPage = $capture(static function (): void {
        MinutesLockPage::render();
    });
    $publishPage = $capture(static function (): void {
        MinutesPublishPage::render();
    });

    if (! str_contains($settingsPage, 'Föreningsinställningar')
        || ! str_contains($settingsPage, 'section=board-roles')
        || ! str_contains($profilePage, 'Profil')
        || ! str_contains($retentionPage, 'Gallring')
        || ! str_contains($lockPage, 'Låsa protokoll')
        || ! str_contains($publishPage, 'Publicera protokoll')
    ) {
        \WP_CLI::error('A settings-only user could not open the association settings pages.');
    }

    add_filter('wp_die_handler', $dieAsException);
    $deniedPages = 0;

    foreach ([
        [MembersPage::class, 'render'],
        [MeetingsPage::class, 'render'],
        [DocumentsPage::class, 'render'],
    ] as $callback) {
        try {
            call_user_func($callback);
        } catch (\RuntimeException) {
            $deniedPages++;
        }
    }

    $_POST = [
        'action' => 'assoc_add_board_role',
        'board_role_name' => 'Lab navigation',
    ];
    $nonceDenied = false;

    try {
        AssociationSettingsPage::addBoardRole();
    } catch (\RuntimeException) {
        $nonceDenied = true;
    }

    $_POST = [];
    $_REQUEST = [];
    remove_filter('wp_die_handler', $dieAsException);

    if ($deniedPages !== 3 || ! $nonceDenied) {
        \WP_CLI::error('Protected pages or a settings change without a nonce were allowed.');
    }

    $_REQUEST['_wpnonce'] = wp_create_nonce('assoc_add_board_role');

    if (check_admin_referer('assoc_add_board_role') < 1) {
        \WP_CLI::error('A valid settings nonce was rejected.');
    }

    $_REQUEST = [];
    $created = WordpressAssociationSettings::boardRoles()->create('Lab navigation', false);

    if ($created->slug() !== 'custom_lab_navigation') {
        \WP_CLI::error('The settings-only user could not add a board role.');
    }

    wp_set_current_user((int) $menuUser);
    $menuDenied = false;

    try {
        WordpressAssociationSettings::boardRoles()->create('Lab menu role', false);
    } catch (NotAllowed) {
        $menuDenied = true;
    }

    $menuHome = $capture(static function (): void {
        AssociationOverviewPage::render();
    });

    if (! $menuDenied || str_contains($menuHome, 'Du har tillgång till föreningsinställningar') || str_contains($menuHome, 'I korthet')) {
        \WP_CLI::error('Menu access alone could change settings or see the dashboard.');
    }

    wp_set_current_user((int) $secretary->ID);
    $secretaryHome = $capture(static function (): void {
        AssociationOverviewPage::render();
    });
    $secretaryMembers = $capture(static function (): void {
        MembersPage::render();
    });
    add_filter('wp_die_handler', $dieAsException);
    $secretarySettingsDenied = false;

    try {
        AssociationSettingsPage::render();
    } catch (\RuntimeException) {
        $secretarySettingsDenied = true;
    }

    remove_filter('wp_die_handler', $dieAsException);

    $secretarySeesOverview = str_contains($secretaryHome, 'Behöver uppmärksamhet')
        || str_contains($secretaryHome, 'I korthet')
        || str_contains($secretaryHome, 'Kom igång med föreningen')
        || str_contains($secretaryHome, 'Föreningen har inga poster ännu.');

    if (! current_user_can(Capabilities::ACCESS_ASSOCIATION)
        || ! current_user_can(Capabilities::VIEW_MEMBERS)
        || ! current_user_can(Capabilities::VIEW_INTERNAL_MEETINGS)
        || current_user_can(Capabilities::MANAGE_ASSOCIATION)
    ) {
        \WP_CLI::error('The secretary lost association access or gained settings.');
    }

    if (! $secretarySeesOverview || str_contains($secretaryHome, 'Du har tillgång till föreningsinställningar')) {
        \WP_CLI::error('The secretary did not get the association overview.');
    }

    if (! str_contains($secretaryMembers, 'Medlemmar') || ! $secretarySettingsDenied) {
        \WP_CLI::error('The secretary lost Members or gained Settings.');
    }
} finally {
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    remove_filter('wp_die_handler', $dieAsException);
    wp_set_current_user(1);

    foreach ($navigationUsers as $login) {
        $user = get_user_by('login', $login);

        if ($user instanceof WP_User) {
            wp_delete_user((int) $user->ID);
        }
    }

    foreach ($navigationRoles as $role) {
        remove_role($role);
    }

    $roleId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", 'custom_lab_navigation'));

    if ($roleId > 0) {
        $wpdb->delete($assignments, ['role_id' => $roleId], ['%d']);
        $wpdb->delete($roles, ['id' => $roleId], ['%d']);
    }

    if (is_array($bundleSnapshot)) {
        update_option(WordpressAccess::OPTION, $bundleSnapshot);
    }

    WordpressAccess::sync();
}

\WP_CLI::success('Association settings lab passed.');

