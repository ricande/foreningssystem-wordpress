<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\BoardPage;
use Foreningssystem\Infrastructure\WordPress\CurrentBoardBlock;
use Foreningssystem\Infrastructure\WordPress\WordpressBoard;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$assignments = $wpdb->prefix . 'assoc_board_assignment';
$roles = $wpdb->prefix . 'assoc_board_role';
$emails = [
    'lab-schedule-anna@example.test',
    'lab-schedule-karin@example.test',
    'lab-schedule-lisa@example.test',
    'lab-schedule-bo@example.test',
];
$roleSlugs = ['lab_schedule_treasurer', 'lab_schedule_chair', 'lab_schedule_term'];

$cleanup = static function () use ($wpdb, $people, $assignments, $roles, $emails, $roleSlugs): void {
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
            $wpdb->delete($assignments, ['person_id' => (int) $personId], ['%d']);
            lab_delete_person_memberships((int) $personId);
            $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
        }
    }
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$section = static function (string $html, string $id): string {
    $needle = 'id="' . $id . '"';
    $start = strpos($html, $needle);

    if ($start === false) {
        return '';
    }

    $next = strpos($html, '<div id="assoc-', $start + strlen($needle));

    return $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
};

$render = static function (): string {
    ob_start();
    BoardPage::render();

    return (string) ob_get_clean();
};

$redirectTo = static function (string $action, array $post) use ($fail): string {
    $_POST = $post;
    $_REQUEST = $post;
    $_REQUEST['_wpnonce'] = wp_create_nonce($action);
    $location = '';
    $filter = static function (string $target) use (&$location): string {
        $location = $target;
        throw new \RuntimeException('redirect');
    };
    add_filter('wp_redirect', $filter, 1);

    try {
        if ($action === 'assoc_cancel_assignment') {
            BoardPage::cancelScheduled();
        } else {
            BoardPage::place();
        }
    } catch (\RuntimeException $error) {
        if ($error->getMessage() !== 'redirect') {
            remove_filter('wp_redirect', $filter, 1);
            $fail($error->getMessage());
        }
    }

    remove_filter('wp_redirect', $filter, 1);
    $_POST = [];
    $_REQUEST = [];

    return $location;
};

$cleanup();
$today = AssociationDate::fromIso(wp_date('Y-m-d'));

if (! $today->isBefore(AssociationDate::fromIso('2027-01-01'))) {
    $fail('The scheduled-board scenario needs a date before 2027-01-01.');
}

foreach ([
    'lab_schedule_treasurer' => ['Treasurer', 0, 930],
    'lab_schedule_chair' => ['Chair', 0, 931],
    'lab_schedule_term' => ['Fixed term', 0, 932],
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

$treasurerId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", 'lab_schedule_treasurer'));
$chairId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", 'lab_schedule_chair'));
$termId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", 'lab_schedule_term'));
wp_set_current_user(1);
$peopleService = WordpressPeople::service();
$board = WordpressBoard::service();
$anna = $peopleService->register('Anna', 'Schedule', 'lab-schedule-anna@example.test', 'LAB-SCHEDULE-ANNA', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$karin = $peopleService->register('Karin', 'Schedule', 'lab-schedule-karin@example.test', 'LAB-SCHEDULE-KARIN', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$lisa = $peopleService->register('Lisa', 'Schedule', 'lab-schedule-lisa@example.test', 'LAB-SCHEDULE-LISA', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$bo = $peopleService->register('Bo', 'Schedule', 'lab-schedule-bo@example.test', 'LAB-SCHEDULE-BO', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$board->place($anna, $treasurerId, AssociationDate::fromIso('2025-03-10'), null, 'anna-schedule@example.test', '', $today);
$board->place($karin, $treasurerId, AssociationDate::fromIso('2027-01-01'), null, 'karin-schedule@example.test', '', $today);
$board->place($bo, $termId, AssociationDate::fromIso('2027-06-01'), AssociationDate::fromIso('2028-03-10'), '', '', $today);

$annaRow = $wpdb->get_row($wpdb->prepare(
    "SELECT started_on, ended_on FROM {$assignments} WHERE person_id = %d AND role_id = %d",
    $anna,
    $treasurerId
), ARRAY_A);
$karinId = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$assignments} WHERE person_id = %d AND role_id = %d",
    $karin,
    $treasurerId
));

if (
    ! is_array($annaRow)
    || (string) $annaRow['started_on'] !== '2025-03-10'
    || (string) $annaRow['ended_on'] !== '2026-12-31'
    || $karinId < 1
) {
    $fail('Scheduling Karin did not end Anna on 2026-12-31.');
}

$html = $render();
$upcoming = $section($html, 'assoc-board-upcoming');
$current = $section($html, 'assoc-board-current');
$roleBox = $section($html, 'assoc-role-' . $treasurerId);

if (
    ! str_contains($current, 'Anna Schedule')
    || str_contains($current, 'Karin Schedule')
    || (! str_contains($upcoming, 'Starts 2027-01-01') && ! str_contains($upcoming, 'Börjar 2027-01-01'))
    || str_contains($upcoming, '2027-01-01 – present')
    || str_contains($upcoming, '2027-01-01 – pågår')
    || ! str_contains($upcoming, '2027-06-01 – 2028-03-10')
) {
    $fail('Upcoming assignments were not described as future dates.');
}

if (
    ! str_contains($roleBox, 'Karin Schedule')
    || ! str_contains($roleBox, 'assoc-cancel-form')
    || str_contains($roleBox, 'assoc-replace-form')
    || str_contains($roleBox, 'assoc-end-form')
) {
    $fail('The treasurer role did not show the scheduled successor instead of another replacement.');
}

$second = $redirectTo('assoc_place_assignment', [
    'person_id' => (string) $lisa,
    'role_id' => (string) $treasurerId,
    'started_on' => '2026-11-01',
    'public_contact' => '',
    'term_label' => '',
]);
$karinStill = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$assignments} WHERE id = %d", $karinId));
$annaEnd = $wpdb->get_var($wpdb->prepare(
    "SELECT ended_on FROM {$assignments} WHERE person_id = %d AND role_id = %d",
    $anna,
    $treasurerId
));

if (! str_contains($second, 'assoc_notice=scheduled') || $karinStill === null || (string) $annaEnd !== '2026-12-31') {
    $fail('A second successor was accepted while Karin was already scheduled.');
}

$cancelled = $redirectTo('assoc_cancel_assignment', [
    'assignment_id' => (string) $karinId,
    'confirm' => '1',
]);
$karinGone = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$assignments} WHERE id = %d", $karinId));
$annaEnd = $wpdb->get_var($wpdb->prepare(
    "SELECT ended_on FROM {$assignments} WHERE person_id = %d AND role_id = %d",
    $anna,
    $treasurerId
));
$_GET['assoc_notice'] = 'cancelled';
$_GET['assoc_role'] = 'lab_schedule_treasurer';
$_GET['assoc_role_name'] = 'Treasurer';
$_GET['assoc_end'] = '2026-12-31';
$noticeHtml = $render();
$_GET = [];

if (
    ! str_contains($cancelled, 'assoc_notice=cancelled')
    || $karinGone !== null
    || (string) $annaEnd !== '2026-12-31'
    || ! str_contains($noticeHtml, '2026-12-31')
    || (str_contains($section($noticeHtml, 'assoc-board-upcoming'), 'Karin Schedule'))
) {
    $fail('Cancelling Karin changed Anna or left the scheduled assignment in place.');
}

$after = $render();
$roleBox = $section($after, 'assoc-role-' . $treasurerId);

if (! str_contains($roleBox, 'assoc-replace-form') || ! str_contains($section($after, 'assoc-board-current'), 'Anna Schedule')) {
    $fail('Anna was no longer replaceable after the scheduled successor was cancelled.');
}

$publicToday = [];

foreach ($board->currentPublic($today) as $seat) {
    $publicToday[] = $seat->personName() . ' ' . $seat->publicContact();
}

$publicLater = [];

foreach ($board->currentPublic(AssociationDate::fromIso('2027-01-01')) as $seat) {
    $publicLater[] = $seat->personName();
}

if (! in_array('Anna Schedule anna-schedule@example.test', $publicToday, true) || in_array('Karin Schedule', $publicLater, true)) {
    $fail('Cancelling the future treasurer changed the public board.');
}

if (! $today->isBefore(AssociationDate::fromIso('2026-03-10'))) {
    wp_set_current_user(0);
    $block = CurrentBoardBlock::render();
    wp_set_current_user(1);

    if (! str_contains($block, 'Anna Schedule') || str_contains($block, 'Karin Schedule') || str_contains($block, 'lab-schedule-')) {
        $fail('The current-board block did not keep Anna after the cancellation.');
    }
}

$annaAssignment = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$assignments} WHERE person_id = %d AND role_id = %d",
    $anna,
    $treasurerId
));
$rejected = $redirectTo('assoc_cancel_assignment', [
    'assignment_id' => (string) $annaAssignment,
    'confirm' => '1',
]);
$annaStill = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$assignments} WHERE id = %d", $annaAssignment));

if (! str_contains($rejected, 'assoc_notice=not_scheduled') || $annaStill === null) {
    $fail('The current treasurer could be cancelled as a scheduled assignment.');
}

$board->place($bo, $chairId, AssociationDate::fromIso('2026-01-01'), AssociationDate::fromIso('2027-03-31'), '', '', $today);
$board->place($lisa, $chairId, AssociationDate::fromIso('2027-02-01'), null, '', '', $today);
$boEnd = $wpdb->get_var($wpdb->prepare(
    "SELECT ended_on FROM {$assignments} WHERE person_id = %d AND role_id = %d AND started_on = %s",
    $bo,
    $chairId,
    '2026-01-01'
));
$lisaStart = $wpdb->get_var($wpdb->prepare(
    "SELECT started_on FROM {$assignments} WHERE person_id = %d AND role_id = %d",
    $lisa,
    $chairId
));

if ((string) $boEnd !== '2027-01-31' || (string) $lisaStart !== '2027-02-01') {
    $fail('Replacing a current holder with a planned end did not shorten that assignment.');
}

$nonceFailed = false;
$capabilityFailed = false;
$_POST = [];
$_REQUEST = [];
add_filter('wp_die_handler', static function () {
    return static function (): void {
        throw new \RuntimeException('wp_die');
    };
}, 1000);

try {
    BoardPage::cancelScheduled();
} catch (\RuntimeException $error) {
    $nonceFailed = $error->getMessage() === 'wp_die';
}

$secretary = get_user_by('login', 'lab-secretary');

if (! $secretary instanceof WP_User) {
    remove_all_filters('wp_die_handler');
    $fail('Lab secretary is missing.');
}

wp_set_current_user((int) $secretary->ID);
clean_user_cache((int) $secretary->ID);

try {
    BoardPage::cancelScheduled();
} catch (\RuntimeException $error) {
    $capabilityFailed = $error->getMessage() === 'wp_die';
}

remove_all_filters('wp_die_handler');
wp_set_current_user(1);
clean_user_cache(1);

if (! $nonceFailed || ! $capabilityFailed) {
    $fail('Cancelling a scheduled assignment did not require a nonce and manage_board.');
}

$cleanup();
\WP_CLI::success('A scheduled board change can be cancelled without reopening the current holder.');
