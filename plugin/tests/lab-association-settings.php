<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\Settings\StructureRuleException;
use Foreningssystem\Domain\Access\Capabilities;
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
        'Styrelseroller',
        'Mötestyper',
        'Öppna föreningsprofil',
        'Föreningsprofil',
        'Kvarhållning',
        'Protokollåsning',
        'Publicering av protokoll',
        'page=foreningsplugin-profile',
        'page=foreningsplugin-retention',
        'page=foreningsplugin-minutes-lock',
        'page=foreningsplugin-minutes-publish',
        'Flytta Ordförande uppåt',
        'Flytta Ordförande nedåt',
    ] as $needle) {
        if (! str_contains($settings, $needle)) {
            \WP_CLI::error('Settings did not show: ' . $needle);
        }
    }

    if (str_contains($settings, 'name="slug"')) {
        \WP_CLI::error('Settings asked for an internal slug.');
    }

    $role = WordpressAssociationSettings::boardRoles()->create('Materialansvarig', false);

    if ($role->slug() !== 'custom_materialansvarig' || $role->allowsMultiple()) {
        \WP_CLI::error('Materialansvarig did not get a single-holder custom slug.');
    }

    $settings = $capture(static function (): void {
        AssociationSettingsPage::render();
    });

    if (! str_contains($settings, 'Materialansvarig') || ! str_contains($settings, 'Redigera Materialansvarig') || str_contains($settings, 'custom_materialansvarig')) {
        \WP_CLI::error('The new board role was not shown as association content.');
    }

    $board = $capture(static function (): void {
        BoardPage::render();
    });

    foreach (['Materialansvarig', 'Aktuell styrelse', 'assoc-board-current', 'assoc-board-history', 'Historik'] as $needle) {
        if (! str_contains($board, $needle)) {
            \WP_CLI::error('Board administration did not show: ' . $needle);
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

    $_GET = ['edit_role' => (string) $role->id()];
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

    $settings = $capture(static function (): void {
        AssociationSettingsPage::render();
    });
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

\WP_CLI::success('Association settings lab passed.');
