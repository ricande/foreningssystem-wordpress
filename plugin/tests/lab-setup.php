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
$logoAttachmentId = 0;

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
    $savedAccess,
    &$logoAttachmentId
): void {
    wp_set_current_user(1);
    clean_user_cache(1);

    if ($logoAttachmentId > 0) {
        wp_delete_attachment($logoAttachmentId, true);
        $logoAttachmentId = 0;
    }

    $roleId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", $roleSlug));
    $typeId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$types} WHERE slug = %s", $typeSlug));

    if ($roleId > 0) {
        $wpdb->delete($assignments, ['role_id' => $roleId], ['%d']);
        $wpdb->delete($roles, ['id' => $roleId], ['%d']);
    }

    if ($typeId > 0) {
        $wpdb->delete($meetings, ['type_id' => $typeId], ['%d']);
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

if (
    ! str_contains($html, 'Association setup')
    && ! str_contains($html, 'Föreningsguiden')
    && ! str_contains($html, 'Welcome')
    && ! str_contains($html, 'Välkommen')
) {
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

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$upload = wp_upload_bits('lab-setup-logo.png', null, $png === false ? '' : $png);

if (! is_array($upload) || ! empty($upload['error']) || ! is_string($upload['file'] ?? null)) {
    $fail('Could not store a lab logo file.');
}

$logoAttachmentId = (int) wp_insert_attachment([
    'post_mime_type' => 'image/png',
    'post_title' => 'Lab setup logo',
    'post_status' => 'inherit',
], $upload['file']);

if ($logoAttachmentId < 1 || ! wp_attachment_is_image($logoAttachmentId)) {
    $fail('Could not create a lab logo attachment.');
}

WordpressAssociationProfile::save(new AssociationProfile(
    'Lab Setup Association',
    '802001-9999',
    "Labgatan 1\n111 22 Lab",
    'lab-setup@example.test',
    '08-123 45',
    AssociationProfile::LANGUAGE_SWEDISH,
    $logoAttachmentId,
    1,
    1
));

$_POST = [
    'name' => 'Lab Setup Association',
    'organization_number' => '802001-8888',
    'address' => "Labgatan 1\n111 22 Lab",
    'email' => 'lab-setup@example.test',
    'phone' => '070-111 22 33',
    'language' => 'en',
    'membership_year_month' => '9',
    'membership_year_day' => '15',
];
$_REQUEST = $_POST;
$_REQUEST['_wpnonce'] = wp_create_nonce('assoc_setup_save_association');
$associationRedirect = '';
$associationRedirectFilter = static function (string $target) use (&$associationRedirect): string {
    $associationRedirect = $target;
    throw new \RuntimeException('redirect');
};
add_filter('wp_redirect', $associationRedirectFilter, 1);

try {
    SetupPage::saveAssociation();
    remove_filter('wp_redirect', $associationRedirectFilter, 1);
    $_POST = [];
    $_REQUEST = [];
    $fail('Association setup save did not redirect.');
} catch (\RuntimeException $error) {
    remove_filter('wp_redirect', $associationRedirectFilter, 1);
    $_POST = [];
    $_REQUEST = [];

    if ($error->getMessage() !== 'redirect') {
        $fail($error->getMessage());
    }
}

$afterAssociationSave = WordpressAssociationProfile::load();

if (
    $afterAssociationSave->logoAttachmentId() !== $logoAttachmentId
    || $afterAssociationSave->name() !== 'Lab Setup Association'
    || $afterAssociationSave->organizationNumber() !== '802001-8888'
    || $afterAssociationSave->address() !== "Labgatan 1\n111 22 Lab"
    || $afterAssociationSave->email() !== 'lab-setup@example.test'
    || $afterAssociationSave->phone() !== '070-111 22 33'
    || $afterAssociationSave->language() !== AssociationProfile::LANGUAGE_ENGLISH
    || $afterAssociationSave->membershipYearStartMonth() !== 9
    || $afterAssociationSave->membershipYearStartDay() !== 15
    || ! str_contains($associationRedirect, 'step=membership')
) {
    $fail('Association setup save cleared the logo or failed to update a field the step shows.');
}

update_option(WordpressAssociationProfile::OPTION_NAME, "Bad\x01Name");
$collapsed = WordpressAssociationProfile::load();

if ($collapsed->name() !== '' || $collapsed->logoAttachmentId() !== null) {
    $fail('A corrupt association name did not collapse profile load, so logo preservation was not proved.');
}

$_POST = [
    'name' => 'Lab Setup Association',
    'organization_number' => '802001-8888',
    'address' => "Labgatan 1\n111 22 Lab",
    'email' => 'lab-setup@example.test',
    'phone' => '070-111 22 33',
    'language' => 'en',
    'membership_year_month' => '9',
    'membership_year_day' => '15',
];
$_REQUEST = $_POST;
$_REQUEST['_wpnonce'] = wp_create_nonce('assoc_setup_save_association');
add_filter('wp_redirect', $associationRedirectFilter, 1);

try {
    SetupPage::saveAssociation();
    remove_filter('wp_redirect', $associationRedirectFilter, 1);
    $_POST = [];
    $_REQUEST = [];
    $fail('Association save after a corrupt profile did not redirect.');
} catch (\RuntimeException $error) {
    remove_filter('wp_redirect', $associationRedirectFilter, 1);
    $_POST = [];
    $_REQUEST = [];

    if ($error->getMessage() !== 'redirect') {
        $fail($error->getMessage());
    }
}

$recovered = WordpressAssociationProfile::load();

if (
    $recovered->logoAttachmentId() !== $logoAttachmentId
    || $recovered->name() !== 'Lab Setup Association'
    || $recovered->phone() !== '070-111 22 33'
    || $recovered->email() !== 'lab-setup@example.test'
) {
    $fail('A collapsed profile load cleared the stored logo or dropped a field the wizard posted.');
}

$membershipHtml = $capture(static function (): void {
    $_GET['step'] = 'membership';
    SetupPage::render();
    unset($_GET['step']);
});

$hasKinds = (
    (str_contains($membershipHtml, 'Ordinary') || str_contains($membershipHtml, 'Ordinarie'))
    && (str_contains($membershipHtml, 'Youth') || str_contains($membershipHtml, 'Ungdom'))
    && (str_contains($membershipHtml, 'Family') || str_contains($membershipHtml, 'Familj'))
    && (str_contains($membershipHtml, 'Company') || str_contains($membershipHtml, 'Företag'))
    && (str_contains($membershipHtml, 'Person') || str_contains($membershipHtml, 'person'))
    && (str_contains($membershipHtml, 'Membership') || str_contains($membershipHtml, 'medlemskap') || str_contains($membershipHtml, 'Medlemskap'))
);

if (! $hasKinds) {
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

$_GET['section'] = 'board-roles';
$boardRolesHtml = $capture([AssociationSettingsPage::class, 'render']);
$_GET['section'] = 'meeting-types';
$meetingTypesHtml = $capture([AssociationSettingsPage::class, 'render']);
unset($_GET['section']);

if (! str_contains($boardRolesHtml, $roleName) || ! str_contains($meetingTypesHtml, $typeName)) {
    $fail('Custom setup role/type did not appear on Settings.');
}

$hubHtml = $capture([AssociationSettingsPage::class, 'render']);

if (
    ! str_contains($hubHtml, 'section=board-roles')
    || ! str_contains($hubHtml, 'section=meeting-types')
    || str_contains($hubHtml, 'assoc_add_board_role')
) {
    $fail('Settings hub did not link to focused structure screens.');
}

$lockRoles = [RoleBundles::SECRETARY, RoleBundles::CHAIR];
$publishRoles = [RoleBundles::CHAIR];

$formDepth = static function (string $html): array {
    $depth = 0;
    $max = 0;
    $nested = 0;
    $length = strlen($html);
    $i = 0;

    while ($i < $length) {
        if (strncasecmp(substr($html, $i, 5), '<form', 5) === 0) {
            $depth++;
            $max = max($max, $depth);
            if ($depth > 1) {
                $nested++;
            }
            $i += 5;
            continue;
        }

        if (strncasecmp(substr($html, $i, 7), '</form>', 7) === 0) {
            $depth = max(0, $depth - 1);
            $i += 7;
            continue;
        }

        $i++;
    }

    return ['max' => $max, 'nested' => $nested, 'final' => $depth];
};

$buttonLabelPos = static function (string $html, array $labels): int|false {
    foreach ($labels as $label) {
        $pos = strpos($html, $label);
        if ($pos !== false) {
            return $pos;
        }
    }

    return false;
};

$wizardSteps = [
    'welcome' => ['Start setup', 'Starta guiden'],
    'association' => ['Save and continue', 'Spara och fortsätt'],
    'membership' => ['Continue', 'Fortsätt'],
    'board' => ['Continue', 'Fortsätt'],
    'meetings' => ['Continue', 'Fortsätt'],
    'minutes' => ['Save and continue', 'Spara och fortsätt'],
    'privacy' => ['Save and continue', 'Spara och fortsätt'],
    'complete' => ['Finish setup', 'Avsluta guiden'],
];

foreach ($wizardSteps as $step => $primaryLabels) {
    $stepHtml = $capture(static function () use ($step): void {
        $_GET['step'] = $step;
        SetupPage::render();
        unset($_GET['step']);
    });
    $depth = $formDepth($stepHtml);
    $primaryPos = $buttonLabelPos($stepHtml, $primaryLabels);

    if ($depth['max'] > 1 || $depth['nested'] > 0 || $depth['final'] !== 0) {
        $fail('Setup step ' . $step . ' has nested or unbalanced forms (max=' . $depth['max'] . ', nested=' . $depth['nested'] . ', final=' . $depth['final'] . ').');
    }

    if ($primaryPos === false) {
        $fail('Setup step ' . $step . ' is missing its primary action button.');
    }

    // Welcome and Association omit Back (Association's only predecessor is Welcome).
    if (in_array($step, ['welcome', 'association'], true)) {
        if (str_contains($stepHtml, 'id="assoc-setup-back-' . $step . '"')
            || str_contains($stepHtml, 'form="assoc-setup-back-' . $step . '"')) {
            $fail('Setup step ' . $step . ' must not show or emit a Back control.');
        }
    }

    if (in_array($step, ['membership', 'board', 'meetings', 'minutes', 'privacy', 'complete'], true)) {
        $backFormPos = strpos($stepHtml, 'id="assoc-setup-back-' . $step . '"');
        if ($backFormPos === false || $backFormPos < $primaryPos) {
            $fail('Setup step ' . $step . ' must emit the Back aux form after the primary button (sibling, not nested).');
        }
    }

    if (in_array($step, ['minutes', 'privacy'], true)) {
        $skipFormPos = strpos($stepHtml, 'id="assoc-setup-skip-' . $step . '"');
        if ($skipFormPos === false || $skipFormPos < $primaryPos) {
            $fail('Setup step ' . $step . ' must emit the Skip aux form after the primary button (sibling, not nested).');
        }
    }

    if (in_array($step, ['welcome', 'association', 'membership', 'board', 'meetings', 'complete'], true)) {
        if (str_contains($stepHtml, 'id="assoc-setup-skip-' . $step . '"')
            || str_contains($stepHtml, 'form="assoc-setup-skip-' . $step . '"')) {
            $fail('Setup step ' . $step . ' must not offer Skip when it does the same thing as the primary button.');
        }
    }

    if (! str_contains($stepHtml, 'aria-current="step"') || ! str_contains($stepHtml, 'is-current') || str_contains($stepHtml, 'is-complete')) {
        $fail('Setup step ' . $step . ' progress did not mark only the current step.');
    }

    if (! str_contains($stepHtml, 'button-primary')) {
        $fail('Setup step ' . $step . ' is missing a primary button.');
    }
}

$minutesHtml = $capture(static function (): void {
    $_GET['step'] = 'minutes';
    SetupPage::render();
    unset($_GET['step']);
});

$saveActionPos = strpos($minutesHtml, 'value="assoc_setup_save_minutes"');
$saveButtonPos = $buttonLabelPos($minutesHtml, ['Save and continue', 'Spara och fortsätt']);

if ($saveActionPos === false || $saveButtonPos === false || $saveButtonPos < $saveActionPos) {
    $fail('Minutes step is missing the save form or Save and continue.');
}

if (str_contains(substr($minutesHtml, $saveActionPos, $saveButtonPos - $saveActionPos), '<form')) {
    $fail('Minutes save form nests another form before Save and continue.');
}

$privacyHtml = $capture(static function (): void {
    $_GET['step'] = 'privacy';
    SetupPage::render();
    unset($_GET['step']);
});

$privacySavePos = strpos($privacyHtml, 'value="assoc_setup_save_privacy"');
$privacyButtonPos = $buttonLabelPos($privacyHtml, ['Save and continue', 'Spara och fortsätt']);

if (
    $privacySavePos === false
    || $privacyButtonPos === false
    || str_contains(substr($privacyHtml, $privacySavePos, $privacyButtonPos - $privacySavePos), '<form')
) {
    $fail('Privacy step nests a form before Save and continue or lacks the save action.');
}

$redirectMinutes = '';
$_POST = [
    'lock_roles' => [RoleBundles::SECRETARY, RoleBundles::CHAIR],
    'publish_roles' => [RoleBundles::CHAIR],
];
$_REQUEST = $_POST;
$_REQUEST['_wpnonce'] = wp_create_nonce('assoc_setup_save_minutes');
$minutesRedirectFilter = static function (string $target) use (&$redirectMinutes): string {
    $redirectMinutes = $target;
    throw new \RuntimeException('redirect');
};
add_filter('wp_redirect', $minutesRedirectFilter, 1);

try {
    SetupPage::saveMinutes();
    remove_filter('wp_redirect', $minutesRedirectFilter, 1);
    $_POST = [];
    $_REQUEST = [];
    $fail('Minutes save did not redirect.');
} catch (\RuntimeException $error) {
    remove_filter('wp_redirect', $minutesRedirectFilter, 1);
    $_POST = [];
    $_REQUEST = [];
    if ($error->getMessage() !== 'redirect') {
        $fail($error->getMessage());
    }
}

$access = WordpressAccess::load();
$stepAfterMinutes = WordpressSetupState::instance()->step();

if (
    ! in_array(Capabilities::FINALIZE_MINUTES, $access->capabilitiesFor(RoleBundles::SECRETARY), true)
    || ! in_array(Capabilities::FINALIZE_MINUTES, $access->capabilitiesFor(RoleBundles::CHAIR), true)
    || ! in_array(Capabilities::PUBLISH_MINUTES, $access->capabilitiesFor(RoleBundles::CHAIR), true)
    || in_array(Capabilities::PUBLISH_MINUTES, $access->capabilitiesFor(RoleBundles::SECRETARY), true)
    || $stepAfterMinutes !== 'privacy'
    || ! str_contains($redirectMinutes, 'step=privacy')
) {
    $fail('Saving minutes with secretary finalize did not grant finalize, keep chair publish, and advance to privacy.');
}

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

$completeHtml = $capture(static function (): void {
    $_GET['step'] = 'complete';
    SetupPage::render();
    unset($_GET['step']);
});

$summaryHas = static function (string $html, array $needles): bool {
    foreach ($needles as $needle) {
        if (str_contains($html, $needle)) {
            return true;
        }
    }

    return false;
};

if (
    ! str_contains($completeHtml, 'Lab Setup Association')
    || ! str_contains($completeHtml, '802001-8888')
    || ! $summaryHas($completeHtml, ['English', 'Engelska'])
    || ! $summaryHas($completeHtml, ['15 September', '15 september'])
    || str_contains($completeHtml, 'Labgatan')
    || str_contains($completeHtml, 'lab-setup@example.test')
    || str_contains($completeHtml, '070-111 22 33')
    || ! $summaryHas($completeHtml, ['Ordinary', 'Ordinarie'])
    || ! $summaryHas($completeHtml, ['Youth', 'Ungdom'])
    || ! $summaryHas($completeHtml, ['Family', 'Familj'])
    || ! $summaryHas($completeHtml, ['Company', 'Företag'])
    || ! str_contains($completeHtml, $roleName)
    || ! str_contains($completeHtml, $typeName)
    || str_contains($completeHtml, $roleSlug)
    || str_contains($completeHtml, $typeSlug)
    || str_contains($completeHtml, 'assoc_secretary')
    || ! $summaryHas($completeHtml, ['Secretary', 'Sekreterare'])
    || ! $summaryHas($completeHtml, ['Chair', 'Ordförande'])
    || ! $summaryHas($completeHtml, ['7 years', '7 år'])
    || str_contains($completeHtml, 'name="years"')
    || str_contains($completeHtml, 'name="address"')
    || str_contains($completeHtml, '<textarea')
) {
    $fail('Complete summary missed a read-only fact, showed an unexposed profile field, or exposed a slug.');
}

\Foreningssystem\Infrastructure\WordPress\WordpressMinutesLock::update([]);
\Foreningssystem\Infrastructure\WordPress\WordpressMinutesPublish::update([]);
$emptyMinutesHtml = $capture(static function (): void {
    $_GET['step'] = 'complete';
    SetupPage::render();
    unset($_GET['step']);
});

if (
    ! $summaryHas($emptyMinutesHtml, ['No role may finalize minutes.', 'Ingen roll får låsa protokoll.'])
    || ! $summaryHas($emptyMinutesHtml, ['No role may publish minutes.', 'Ingen roll får publicera protokoll.'])
) {
    \Foreningssystem\Infrastructure\WordPress\WordpressMinutesLock::update($lockRoles);
    \Foreningssystem\Infrastructure\WordPress\WordpressMinutesPublish::update($publishRoles);
    $fail('Complete summary did not say when no role may finalize or publish minutes.');
}

\Foreningssystem\Infrastructure\WordPress\WordpressMinutesLock::update($lockRoles);
\Foreningssystem\Infrastructure\WordPress\WordpressMinutesPublish::update($publishRoles);

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

$adminCapsBefore = array_keys(array_filter(wp_get_current_user()->allcaps));
$_GET['page'] = 'foreningsplugin';
$_GET['assoc_notice'] = 'setup_complete';
$adminNotice = $capture([Plugin::class, 'setupSuccessNotice']);
unset($_GET['assoc_notice']);
$adminCapsAfter = array_keys(array_filter(wp_get_current_user()->allcaps));
sort($adminCapsBefore);
sort($adminCapsAfter);

if (
    ! str_contains($adminNotice, 'notice-success')
    || (
        ! str_contains($adminNotice, 'Association setup is complete')
        && ! str_contains($adminNotice, 'Föreningsguiden är klar')
    )
    || ! str_contains($adminNotice, 'page=foreningsplugin-members')
    || ! str_contains($adminNotice, 'page=foreningsplugin-board')
    || ! str_contains($adminNotice, 'page=foreningsplugin-meetings')
    || $adminCapsBefore !== $adminCapsAfter
) {
    $fail('Administrator setup success notice missed the message, operational links, or changed capabilities.');
}

$settingsRole = 'assoc_lab_setup_settings';
$settingsLogin = 'lab-setup-settings-only';
$existingSettings = get_user_by('login', $settingsLogin);

if ($existingSettings instanceof WP_User) {
    wp_delete_user((int) $existingSettings->ID);
}

remove_role($settingsRole);
add_role($settingsRole, 'Lab setup settings only', [
    'read' => true,
    Capabilities::ACCESS_ASSOCIATION => true,
    Capabilities::MANAGE_ASSOCIATION => true,
]);
$settingsUserId = wp_insert_user([
    'user_login' => $settingsLogin,
    'user_pass' => 'test',
    'user_email' => 'lab-setup-settings-only@example.test',
    'role' => $settingsRole,
]);

if (is_wp_error($settingsUserId)) {
    $fail('Could not create settings-only user for setup success notice.');
}

clean_user_cache((int) $settingsUserId);
wp_set_current_user((int) $settingsUserId);
$settingsCapsBefore = array_keys(array_filter(wp_get_current_user()->allcaps));
$_GET['page'] = 'foreningsplugin';
$_GET['assoc_notice'] = 'setup_complete';
$settingsNotice = $capture([Plugin::class, 'setupSuccessNotice']);
unset($_GET['page'], $_GET['assoc_notice']);
$settingsCapsAfter = array_keys(array_filter(wp_get_current_user()->allcaps));
sort($settingsCapsBefore);
sort($settingsCapsAfter);

if (
    ! str_contains($settingsNotice, 'notice-success')
    || str_contains($settingsNotice, 'page=foreningsplugin-members')
    || str_contains($settingsNotice, 'page=foreningsplugin-board')
    || str_contains($settingsNotice, 'page=foreningsplugin-meetings')
    || str_contains($settingsNotice, '<ul>')
    || $settingsCapsBefore !== $settingsCapsAfter
) {
    wp_set_current_user(1);
    wp_delete_user((int) $settingsUserId);
    remove_role($settingsRole);
    $fail('Settings-only setup success notice exposed operational links, rendered an empty list, or changed capabilities.');
}

wp_set_current_user(1);
wp_delete_user((int) $settingsUserId);
remove_role($settingsRole);
clean_user_cache(1);

$completeSlugs = $submenuSlugs();

foreach (['foreningsplugin-members', 'foreningsplugin-board', 'foreningsplugin-meetings', 'foreningsplugin-settings'] as $slug) {
    if (! in_array($slug, $completeSlugs, true)) {
        $fail('Completed setup did not restore normal Association navigation. Missing ' . $slug);
    }
}

$settingsAfter = $capture([AssociationSettingsPage::class, 'render']);

if (
    ! str_contains($settingsAfter, 'Run setup guide again')
    && ! str_contains($settingsAfter, 'Öppna föreningsguiden igen')
) {
    $fail('Settings is missing the reopen setup guide link.');
}

$reopen = $capture([SetupPage::class, 'render']);

if (
    (! str_contains($reopen, 'Association setup') && ! str_contains($reopen, 'Föreningsguiden'))
    || WordpressSetupState::instance()->version() !== 1
) {
    $fail('Reopening the setup guide reset completion.');
}

$_POST = [
    'name' => 'Lab Setup Association',
    'organization_number' => '802001-8888',
    'address' => "Labgatan 1\n111 22 Lab",
    'email' => 'lab-setup-reopen@example.test',
    'phone' => '070-111 22 33',
    'language' => 'en',
    'membership_year_month' => '9',
    'membership_year_day' => '15',
];
$_REQUEST = $_POST;
$_REQUEST['_wpnonce'] = wp_create_nonce('assoc_setup_save_association');
$reopenRedirect = '';
$reopenRedirectFilter = static function (string $target) use (&$reopenRedirect): string {
    $reopenRedirect = $target;
    throw new \RuntimeException('redirect');
};
add_filter('wp_redirect', $reopenRedirectFilter, 1);

try {
    SetupPage::saveAssociation();
    remove_filter('wp_redirect', $reopenRedirectFilter, 1);
    $_POST = [];
    $_REQUEST = [];
    $fail('Reopened association save did not redirect.');
} catch (\RuntimeException $error) {
    remove_filter('wp_redirect', $reopenRedirectFilter, 1);
    $_POST = [];
    $_REQUEST = [];

    if ($error->getMessage() !== 'redirect') {
        $fail($error->getMessage());
    }
}

$reopenedProfile = WordpressAssociationProfile::load();

if (
    $reopenedProfile->logoAttachmentId() !== $logoAttachmentId
    || $reopenedProfile->name() !== 'Lab Setup Association'
    || $reopenedProfile->email() !== 'lab-setup-reopen@example.test'
    || $reopenedProfile->organizationNumber() !== '802001-8888'
    || $reopenedProfile->address() !== "Labgatan 1\n111 22 Lab"
    || WordpressSetupState::instance()->version() !== 1
    || ! WordpressSetupState::instance()->isComplete()
) {
    $fail('Reopening setup and saving Association destroyed the profile or completion state.');
}

wp_dequeue_style('foreningsplugin-admin');
wp_deregister_style('foreningsplugin-admin');
Plugin::enqueueAdminAssets('index.php');
Plugin::enqueueAdminAssets('plugins.php');
$styles = wp_styles();

if (in_array('foreningsplugin-admin', $styles->queue, true)) {
    $fail('Association admin CSS was enqueued on a non-plugin admin screen.');
}

Plugin::enqueueAdminAssets('foreningsplugin_page_foreningsplugin-settings');
Plugin::enqueueAdminAssets('foreningsplugin_page_foreningsplugin-setup');
$style = $styles->registered['foreningsplugin-admin'] ?? null;

if (
    ! in_array('foreningsplugin-admin', $styles->queue, true)
    || ! is_object($style)
    || ! str_contains((string) $style->src, 'admin.css')
) {
    $fail('Association admin CSS was not enqueued on Settings and setup.');
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
