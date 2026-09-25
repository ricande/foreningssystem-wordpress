<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Association\AssociationProfile;
use Foreningssystem\Domain\Privacy\RetentionPeriod;
use Foreningssystem\Infrastructure\WordPress\AssociationSettingsPage;
use Foreningssystem\Infrastructure\WordPress\Plugin;
use Foreningssystem\Infrastructure\WordPress\SetupPage;
use Foreningssystem\Infrastructure\WordPress\WordpressAssociationProfile;
use Foreningssystem\Infrastructure\WordPress\WordpressAssociationSettings;
use Foreningssystem\Infrastructure\WordPress\WordpressRetention;
use Foreningssystem\Infrastructure\WordPress\WordpressSetupState;
use Foreningssystem\Infrastructure\WordPress\WordpressAccess;
use Foreningssystem\Domain\Access\RoleBundles;

global $wpdb, $menu, $submenu;

$people = $wpdb->prefix . 'assoc_person';
$assignments = $wpdb->prefix . 'assoc_board_assignment';
$meetings = $wpdb->prefix . 'assoc_meeting';
$roles = $wpdb->prefix . 'assoc_board_role';
$types = $wpdb->prefix . 'assoc_meeting_type';
$roleSlug = 'custom_lab_setup_material';
$typeSlug = 'custom_lab_setup_budget';
$roleName = 'Lab setup material';
$typeName = 'Lab setup budget';

$capture = static function (callable $render): string {
    ob_start();
    $render();

    return (string) ob_get_clean();
};

$savedVersion = get_option(WordpressSetupState::OPTION_VERSION, false);
$savedStep = get_option(WordpressSetupState::OPTION_STEP, false);
$savedPending = get_option(WordpressSetupState::OPTION_REDIRECT_PENDING, false);
$savedNotice = get_option(WordpressSetupState::OPTION_SUCCESS_NOTICE, false);
$savedProfile = WordpressAssociationProfile::load();
$savedRetention = WordpressRetention::load()->years();
$savedAccess = WordpressAccess::load();

$cleanup = static function () use (
    $wpdb,
    $people,
    $assignments,
    $meetings,
    $roles,
    $types,
    $roleSlug,
    $typeSlug,
    $savedVersion,
    $savedStep,
    $savedPending,
    $savedNotice,
    $savedProfile,
    $savedRetention,
    $savedAccess
): void {
    wp_set_current_user(1);
    clean_user_cache(1);

    $roleId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", $roleSlug));
    $typeId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$types} WHERE slug = %s", $typeSlug));

    if ($roleId > 0) {
        $wpdb->delete($assignments, ['role_id' => $roleId], ['%d']);
        $wpdb->delete($roles, ['id' => $roleId], ['%d']);
    }

    if ($typeId > 0) {
        $wpdb->delete($meetings, ['meeting_type_id' => $typeId], ['%d']);
        $wpdb->delete($types, ['id' => $typeId], ['%d']);
    }

    if ($savedVersion === false) {
        delete_option(WordpressSetupState::OPTION_VERSION);
    } else {
        update_option(WordpressSetupState::OPTION_VERSION, $savedVersion, false);
    }

    if ($savedStep === false) {
        delete_option(WordpressSetupState::OPTION_STEP);
    } else {
        update_option(WordpressSetupState::OPTION_STEP, $savedStep, false);
    }

    if ($savedPending === false) {
        delete_option(WordpressSetupState::OPTION_REDIRECT_PENDING);
    } else {
        update_option(WordpressSetupState::OPTION_REDIRECT_PENDING, $savedPending, false);
    }

    if ($savedNotice === false) {
        delete_option(WordpressSetupState::OPTION_SUCCESS_NOTICE);
    } else {
        update_option(WordpressSetupState::OPTION_SUCCESS_NOTICE, $savedNotice, false);
    }

    WordpressAssociationProfile::save($savedProfile);
    WordpressRetention::save(new RetentionPeriod($savedRetention));
    WordpressAccess::save($savedAccess);
    WordpressAccess::sync();

    $user = get_user_by('login', 'lab-setup-unauthorized');

    if ($user instanceof WP_User) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user((int) $user->ID);
    }

    remove_role('assoc_lab_setup_unauthorized');
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$submenuSlugs = static function () use (&$menu, &$submenu): array {
    if (! function_exists('add_menu_page')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $menu = [];
    $submenu = [];
    Plugin::registerAdminMenu();
    $slugs = [];

    foreach ($submenu['foreningsplugin'] ?? [] as $item) {
        if (is_array($item) && isset($item[2])) {
            $slugs[] = (string) $item[2];
        }
    }

    return $slugs;
};

wp_set_current_user(1);
clean_user_cache(1);

$beforePeople = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$people}");
$beforeAssignments = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$assignments}");
$beforeMeetings = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$meetings}");

WordpressSetupState::instance()->markFreshIncomplete();
$incompleteSlugs = $submenuSlugs();
$operational = [
    'foreningsplugin-members',
    'foreningsplugin-board',
    'foreningsplugin-meetings',
    'foreningsplugin-decisions',
    'foreningsplugin-tasks',
    'foreningsplugin-documents',
    'foreningsplugin-settings',
];

foreach ($operational as $slug) {
    if (in_array($slug, $incompleteSlugs, true)) {
        $fail('Incomplete setup still showed operational menu item ' . $slug);
    }
}

if (! in_array(SetupPage::PAGE, $incompleteSlugs, true) && ! in_array('foreningsplugin', $incompleteSlugs, true)) {
    $fail('Incomplete setup did not expose Get started. Saw: ' . implode(',', $incompleteSlugs));
}

$html = $capture([SetupPage::class, 'render']);

if (! str_contains($html, 'Association setup') && ! str_contains($html, 'Welcome')) {
    $fail('The setup page did not render welcome content.');
}

add_role('assoc_lab_setup_unauthorized', 'Lab setup unauthorized', [
    'read' => true,
    Capabilities::ACCESS_ASSOCIATION => true,
    Capabilities::VIEW_MEMBERS => true,
]);
$unauthorizedId = wp_insert_user([
    'user_login' => 'lab-setup-unauthorized',
    'user_pass' => 'test',
    'user_email' => 'lab-setup-unauthorized@example.test',
    'role' => 'assoc_lab_setup_unauthorized',
]);

if (is_wp_error($unauthorizedId)) {
    $fail('Could not create unauthorized setup lab user.');
}

wp_set_current_user((int) $unauthorizedId);
clean_user_cache((int) $unauthorizedId);
$denied = false;

try {
    WordpressAssociationProfile::save(new AssociationProfile(
        'Should fail',
        '',
        '',
        '',
        '',
        AssociationProfile::LANGUAGE_SWEDISH,
        null,
        1,
        1
    ));
} catch (NotAllowed $error) {
    $denied = $error->getMessage() === Capabilities::MANAGE_ASSOCIATION;
}

if (! $denied) {
    $fail('A user without manage_association changed the association profile during setup lab.');
}

wp_set_current_user(1);
clean_user_cache(1);

switch_to_locale('sv_SE');
$swedishWelcome = __('Welcome', 'foreningsplugin');
$swedishGetStarted = __('Get started', 'foreningsplugin');
restore_previous_locale();

if ($swedishWelcome !== 'Välkommen' || $swedishGetStarted !== 'Kom igång') {
    $fail('Swedish welcome/get started labels are missing. Got welcome=' . $swedishWelcome . ' get_started=' . $swedishGetStarted);
}

$profile = new AssociationProfile(
    'Lab Setup Association',
    '802001-9999',
    "Labgatan 1\n111 22 Lab",
    'lab-setup@example.test',
    '08-123 45',
    AssociationProfile::LANGUAGE_SWEDISH,
    null,
    1,
    1
);
WordpressAssociationProfile::save($profile);
$loaded = WordpressAssociationProfile::load();

if ($loaded->name() !== 'Lab Setup Association' || $loaded->email() !== 'lab-setup@example.test') {
    $fail('Wizard profile save did not use the canonical association profile store.');
}

$membershipHtml = $capture(static function (): void {
    $_GET['step'] = 'membership';
    SetupPage::render();
    unset($_GET['step']);
});

foreach (['ordinary', 'youth', 'family', 'company', 'Person', 'Membership', 'WordPress'] as $needle) {
    if (! str_contains(strtolower($membershipHtml), strtolower($needle)) && ! str_contains($membershipHtml, 'Ordinary') && ! str_contains($membershipHtml, 'Youth')) {
        // Soft check below.
    }
}

if (
    ! str_contains($membershipHtml, 'Ordinary')
    || ! str_contains($membershipHtml, 'Youth')
    || ! str_contains($membershipHtml, 'Family')
    || ! str_contains($membershipHtml, 'Company')
    || ! str_contains($membershipHtml, 'Person')
    || ! str_contains($membershipHtml, 'Membership')
) {
    $fail('Membership step did not explain kinds and Person vs Membership.');
}

WordpressAssociationSettings::boardRoles()->create($roleName, false);
WordpressAssociationSettings::meetingTypes()->create($typeName);
$roleFound = false;
$typeFound = false;

foreach (WordpressAssociationSettings::boardRoles()->catalog() as $role) {
    if ($role->name() === $roleName) {
        $roleFound = true;
    }
}

foreach (WordpressAssociationSettings::meetingTypes()->catalog() as $type) {
    if ($type->name() === $typeName) {
        $typeFound = true;
    }
}

if (! $roleFound || ! $typeFound) {
    $fail('Custom board role or meeting type from setup services did not appear in the catalog.');
}

$settingsHtml = $capture([AssociationSettingsPage::class, 'render']);

if (! str_contains($settingsHtml, $roleName) || ! str_contains($settingsHtml, $typeName)) {
    $fail('Custom setup role/type did not appear on Settings.');
}

$lockRoles = [RoleBundles::SECRETARY, RoleBundles::CHAIR];
$publishRoles = [RoleBundles::CHAIR];
\Foreningssystem\Infrastructure\WordPress\WordpressMinutesLock::update($lockRoles);
\Foreningssystem\Infrastructure\WordPress\WordpressMinutesPublish::update($publishRoles);
$access = WordpressAccess::load();

if (
    ! in_array(Capabilities::FINALIZE_MINUTES, $access->capabilitiesFor(RoleBundles::SECRETARY), true)
    || ! in_array(Capabilities::PUBLISH_MINUTES, $access->capabilitiesFor(RoleBundles::CHAIR), true)
) {
    $fail('Minutes permissions were not saved through the canonical services.');
}

WordpressRetention::save(new RetentionPeriod(7));

if (WordpressRetention::load()->years() !== 7) {
    $fail('Retention 7 years was not stored.');
}

$afterPeopleMid = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$people}");
$afterAssignmentsMid = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$assignments}");
$afterMeetingsMid = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$meetings}");

if ($afterPeopleMid !== $beforePeople || $afterAssignmentsMid !== $beforeAssignments || $afterMeetingsMid !== $beforeMeetings) {
    $fail('Setup steps created people, board assignments, or meetings before finish.');
}

$state = WordpressSetupState::instance();
$state->complete();

if (! $state->isComplete() || $state->version() !== 1) {
    $fail('Finish did not set assoc_setup_version to 1.');
}

$completeSlugs = $submenuSlugs();

foreach (['foreningsplugin-members', 'foreningsplugin-board', 'foreningsplugin-meetings', 'foreningsplugin-settings'] as $slug) {
    if (! in_array($slug, $completeSlugs, true)) {
        $fail('Completed setup did not restore normal Association navigation. Missing ' . $slug);
    }
}

$settingsAfter = $capture([AssociationSettingsPage::class, 'render']);

if (! str_contains($settingsAfter, 'Run setup guide again')) {
    $fail('Settings is missing the reopen setup guide link.');
}

$reopen = $capture([SetupPage::class, 'render']);

if (! str_contains($reopen, 'Association setup') || WordpressSetupState::instance()->version() !== 1) {
    $fail('Reopening the setup guide reset completion.');
}

$afterPeople = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$people}");
$afterAssignments = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$assignments}");
$afterMeetings = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$meetings}");

if ($afterPeople !== $beforePeople || $afterAssignments !== $beforeAssignments || $afterMeetings !== $beforeMeetings) {
    $fail('Finishing setup created people, board assignments, or meetings.');
}

$schema = get_option('assoc_schema_version');

if ((string) $schema !== '16' || Plugin::VERSION !== '0.1.0') {
    $fail('Setup lab changed schema or plugin version.');
}

$adopt = new WordpressSetupState();
delete_option(WordpressSetupState::OPTION_VERSION);
delete_option(WordpressSetupState::OPTION_REDIRECT_PENDING);
WordpressSetupState::adoptPreWizardIfNeeded();

if (! WordpressSetupState::instance()->isComplete()) {
    $fail('Pre-wizard adoption for an existing schema install failed.');
}

$cleanup();
\WP_CLI::success('Setup wizard lab passed.');
