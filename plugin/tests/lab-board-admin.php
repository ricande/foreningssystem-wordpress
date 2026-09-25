<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\BoardPage;
use Foreningssystem\Infrastructure\WordPress\BoardScreen;
use Foreningssystem\Infrastructure\WordPress\CurrentBoardBlock;
use Foreningssystem\Infrastructure\WordPress\WordpressBoard;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$assignments = $wpdb->prefix . 'assoc_board_assignment';
$roles = $wpdb->prefix . 'assoc_board_role';
$emails = [
    'lab-board-ux-anna@example.test',
    'lab-board-ux-karin@example.test',
    'lab-board-ux-nils@example.test',
    'lab-board-ux-bo@example.test',
    'lab-board-ux-liv@example.test',
    'lab-board-ux-dina@example.test',
];
$roleSlugs = ['lab_ux_treasurer', 'lab_ux_member'];

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

    $next = strpos($html, '<div id="assoc-board-', $start + strlen($needle));

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
        if ($action === 'assoc_end_assignment') {
            BoardPage::end();
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
wp_set_current_user(1);
ob_start();
BoardScreen::render([], [], [], true, '');
$empty = (string) ob_get_clean();

if (
    ! str_contains($empty, 'No current board assignments.')
    && ! str_contains($empty, 'Inga aktuella styrelseuppdrag.')
) {
    $fail('The empty board did not explain that there are no current assignments.');
}

if (
    ! str_contains($empty, 'Add the first board assignment.')
    && ! str_contains($empty, 'Lägg till det första styrelseuppdraget.')
) {
    $fail('The empty board did not offer the first assignment.');
}

if (
    ! str_contains($empty, 'Get started')
    && ! str_contains($empty, 'Kom igång')
) {
    $fail('The empty board did not offer Get started.');
}

if (
    str_contains($empty, 'assoc-replace-form')
    || str_contains($empty, 'assoc-place-form')
    || str_contains($empty, 'id="assoc-board-history"')
) {
    $fail('The overview still rendered mutation forms or inline history.');
}

if (
    ! str_contains($empty, 'assoc-board-history-link')
    || (! str_contains($empty, 'Show history') && ! str_contains($empty, 'Visa historik'))
) {
    $fail('The overview did not link to the history view.');
}

if ($wpdb->insert($roles, [
    'slug' => 'lab_ux_treasurer',
    'name' => 'Treasurer',
    'allows_multiple' => 0,
    'sort_order' => 910,
], ['%s', '%s', '%d', '%d']) === false) {
    $fail('The lab treasurer role could not be created.');
}

$treasurerId = (int) $wpdb->insert_id;

if ($wpdb->insert($roles, [
    'slug' => 'lab_ux_member',
    'name' => 'Board member',
    'allows_multiple' => 1,
    'sort_order' => 920,
], ['%s', '%s', '%d', '%d']) === false) {
    $fail('The lab multi-holder role could not be created.');
}

$memberRoleId = (int) $wpdb->insert_id;
$peopleService = WordpressPeople::service();
$board = WordpressBoard::service();
$anna = $peopleService->register('Anna', 'Lab', 'lab-board-ux-anna@example.test', 'LAB-UX-BOARD-ANNA', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$karin = $peopleService->register('Karin', 'Lab', 'lab-board-ux-karin@example.test', 'LAB-UX-BOARD-KARIN', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$nils = $peopleService->register('Nils', 'Lab', 'lab-board-ux-nils@example.test', 'LAB-UX-BOARD-NILS', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$bo = $peopleService->register('Bo', 'Lab', 'lab-board-ux-bo@example.test', 'LAB-UX-BOARD-BO', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$liv = $peopleService->register('Liv', 'Lab', 'lab-board-ux-liv@example.test', 'LAB-UX-BOARD-LIV', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$dina = $peopleService->register('Dina', 'Lab', 'lab-board-ux-dina@example.test', 'LAB-UX-BOARD-DINA', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$board->place($anna, $treasurerId, AssociationDate::fromIso('2025-03-10'), null, 'kassor-anna@example.test', '2025–2026');
$first = $render();
$current = $section($first, 'assoc-board-current');
$_GET['assoc_view'] = 'history';
$historyPage = $render();
unset($_GET['assoc_view']);
$history = $section($historyPage, 'assoc-board-history');

if (! str_contains($current, 'Anna Lab') || ! str_contains($current, '2025-03-10') || ! str_contains($current, 'Treasurer')) {
    $fail('The current board did not show Anna as treasurer.');
}

if (str_contains($history, 'Anna Lab')) {
    $fail('The current treasurer was listed as history.');
}

if (
    ! str_contains($first, 'Change the board')
    && ! str_contains($first, 'Ändra styrelsen')
) {
    $fail('A populated board did not offer Change the board.');
}

if (str_contains($first, 'assoc-replace-form') || str_contains($first, 'assoc-place-form')) {
    $fail('The overview still showed assignment forms instead of the wizard.');
}

$_GET['assoc_board_step'] = 'task';
$taskPage = $render();
unset($_GET['assoc_board_step']);

if (
    (! str_contains($taskPage, 'Replace role') && ! str_contains($taskPage, 'Ersätt roll'))
    || (! str_contains($taskPage, 'Add holder') && ! str_contains($taskPage, 'Lägg till innehavare'))
    || str_contains($taskPage, '>Replace Board member<')
    || str_contains($taskPage, '>Ersätt Board member<')
) {
    $fail('The wizard task step did not offer replace and add correctly.');
}

$_GET['assoc_board_step'] = 'role';
$_GET['assoc_board_task'] = 'replace';
$replaceRoles = $render();
unset($_GET['assoc_board_step'], $_GET['assoc_board_task']);

if (! str_contains($replaceRoles, 'Treasurer') || str_contains($replaceRoles, 'Board member')) {
    $fail('Replace role listed the wrong roles.');
}

$replaced = $board->place($karin, $treasurerId, AssociationDate::fromIso('2026-03-10'), null, 'kassor@example.test', '2026–2027');

if ($replaced !== 'replaced') {
    $fail('Replacing the treasurer did not report a replacement.');
}

$annaRow = $wpdb->get_row($wpdb->prepare(
    "SELECT started_on, ended_on, public_contact FROM {$assignments} WHERE person_id = %d AND role_id = %d",
    $anna,
    $treasurerId
), ARRAY_A);
$karinRow = $wpdb->get_row($wpdb->prepare(
    "SELECT started_on, ended_on, public_contact FROM {$assignments} WHERE person_id = %d AND role_id = %d",
    $karin,
    $treasurerId
), ARRAY_A);

if (
    ! is_array($annaRow)
    || (string) $annaRow['started_on'] !== '2025-03-10'
    || (string) $annaRow['ended_on'] !== '2026-03-09'
    || (string) $annaRow['public_contact'] !== 'kassor-anna@example.test'
    || ! is_array($karinRow)
    || (string) $karinRow['started_on'] !== '2026-03-10'
    || $karinRow['ended_on'] !== null
    || (string) $karinRow['public_contact'] !== 'kassor@example.test'
) {
    $fail('The treasurer replacement did not keep Anna through 2026-03-09 and start Karin on 2026-03-10.');
}

$after = $render();
$current = $section($after, 'assoc-board-current');
$_GET['assoc_view'] = 'history';
$history = $section($render(), 'assoc-board-history');
unset($_GET['assoc_view']);

if (! str_contains($current, 'Karin Lab') || str_contains($current, 'Anna Lab')) {
    $fail('Karin was not the current treasurer.');
}

if (! str_contains($history, 'Anna Lab') || ! str_contains($history, '2025-03-10') || ! str_contains($history, '2026-03-09')) {
    $fail('Anna was not kept in treasurer history.');
}

$on = AssociationDate::fromIso('2026-09-24');
$shown = [];

foreach ($board->currentPublic($on) as $seat) {
    if ($seat->personName() === 'Anna Lab') {
        $fail('The public board still showed Anna on 2026-09-24.');
    }

    if (str_contains($seat->publicContact(), 'lab-board-ux-')) {
        $fail('The public board used a private email.');
    }

    if ($seat->personName() === 'Karin Lab') {
        $shown[] = $seat->publicContact();
    }
}

if ($shown !== ['kassor@example.test']) {
    $fail('The public board did not show Karin with the assignment contact.');
}

$today = AssociationDate::fromIso(wp_date('Y-m-d'));

if (! $today->isBefore(AssociationDate::fromIso('2026-03-10'))) {
    wp_set_current_user(0);
    $publicHtml = CurrentBoardBlock::render();
    wp_set_current_user(1);

    if (! str_contains($publicHtml, 'Karin Lab') || str_contains($publicHtml, 'Anna Lab') || str_contains($publicHtml, 'lab-board-ux-')) {
        $fail('The current-board block did not follow the replacement.');
    }
}

$nilsMembership = null;

foreach ($peopleService->listPeople() as $record) {
    if ($record->person()->id() === $nils) {
        $nilsMembership = $record->membership()?->id();
    }
}

if ($nilsMembership === null) {
    $fail('Nils has no membership to end.');
}

$peopleService->endMembership($nilsMembership, AssociationDate::fromIso('2024-12-31'));
$failed = false;

try {
    $board->place($nils, $treasurerId, AssociationDate::fromIso('2026-04-01'), null, '', '');
} catch (\Foreningssystem\Domain\Board\BoardRuleException) {
    $failed = true;
}

$karinStill = $wpdb->get_var($wpdb->prepare(
    "SELECT ended_on FROM {$assignments} WHERE person_id = %d AND role_id = %d",
    $karin,
    $treasurerId
));

if (! $failed || $karinStill !== null) {
    $fail('A non-member replacement changed the current treasurer.');
}

$board->place($bo, $memberRoleId, AssociationDate::fromIso('2026-01-01'), null, '', '');
$added = $board->place($liv, $memberRoleId, AssociationDate::fromIso('2026-02-01'), null, '', '');
$boEnded = $wpdb->get_var($wpdb->prepare(
    "SELECT ended_on FROM {$assignments} WHERE person_id = %d AND role_id = %d",
    $bo,
    $memberRoleId
));
$both = $render();
$current = $section($both, 'assoc-board-current');

if ($added !== 'saved' || $boEnded !== null || ! str_contains($current, 'Bo Lab') || ! str_contains($current, 'Liv Lab')) {
    $fail('Adding a holder to a multi-holder role ended the existing holder.');
}

$peopleService->markDeceased($dina, AssociationDate::fromIso('2026-01-01'));
$deceased = $redirectTo('assoc_place_assignment', [
    'person_id' => (string) $dina,
    'role_id' => (string) $treasurerId,
    'started_on' => '2026-05-01',
    'public_contact' => '',
    'term_label' => '',
]);
$karinStill = $wpdb->get_var($wpdb->prepare(
    "SELECT ended_on FROM {$assignments} WHERE person_id = %d AND role_id = %d",
    $karin,
    $treasurerId
));

if (! str_contains($deceased, 'assoc_notice=deceased') || $karinStill !== null) {
    $fail('A deceased person was accepted as treasurer.');
}

$unknownPerson = $redirectTo('assoc_place_assignment', [
    'person_id' => '999999',
    'role_id' => (string) $treasurerId,
    'started_on' => '2026-05-01',
    'public_contact' => '',
    'term_label' => '',
]);
$unknownRole = $redirectTo('assoc_place_assignment', [
    'person_id' => (string) $karin,
    'role_id' => '999999',
    'started_on' => '2026-05-01',
    'public_contact' => '',
    'term_label' => '',
]);
$unknownAssignment = $redirectTo('assoc_end_assignment', [
    'assignment_id' => '999999',
    'ended_on' => '2026-09-24',
    'confirm' => '1',
]);
$boAssignment = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$assignments} WHERE person_id = %d AND role_id = %d",
    $bo,
    $memberRoleId
));
$unconfirmed = $redirectTo('assoc_end_assignment', [
    'assignment_id' => (string) $boAssignment,
    'ended_on' => '2026-08-31',
]);
$tooEarly = $redirectTo('assoc_end_assignment', [
    'assignment_id' => (string) $boAssignment,
    'ended_on' => '2025-01-01',
    'confirm' => '1',
]);
$boEnded = $wpdb->get_var($wpdb->prepare(
    "SELECT ended_on FROM {$assignments} WHERE id = %d",
    $boAssignment
));

if (
    ! str_contains($unknownPerson, 'assoc_notice=person')
    || ! str_contains($unknownRole, 'assoc_notice=role')
    || ! str_contains($unknownAssignment, 'assoc_notice=assignment')
    || ! str_contains($unconfirmed, 'assoc_notice=confirm')
    || ! str_contains($tooEarly, 'assoc_notice=before_start')
    || $boEnded !== null
) {
    $fail('Invalid board changes were not rejected cleanly.');
}

$ended = $redirectTo('assoc_end_assignment', [
    'assignment_id' => (string) $boAssignment,
    'ended_on' => '2026-08-31',
    'confirm' => '1',
]);
$again = $redirectTo('assoc_end_assignment', [
    'assignment_id' => (string) $boAssignment,
    'ended_on' => '2026-09-01',
    'confirm' => '1',
]);
$boRow = $wpdb->get_row($wpdb->prepare(
    "SELECT started_on, ended_on FROM {$assignments} WHERE id = %d",
    $boAssignment
), ARRAY_A);
$livEnded = $wpdb->get_var($wpdb->prepare(
    "SELECT ended_on FROM {$assignments} WHERE person_id = %d AND role_id = %d",
    $liv,
    $memberRoleId
));
$history = $section((static function () use ($render): string {
    $_GET['assoc_view'] = 'history';
    $html = $render();
    unset($_GET['assoc_view']);

    return $html;
})(), 'assoc-board-history');

if (
    ! str_contains($ended, 'assoc_notice=ended')
    || ! str_contains($again, 'assoc_notice=already_ended')
    || ! is_array($boRow)
    || (string) $boRow['started_on'] !== '2026-01-01'
    || (string) $boRow['ended_on'] !== '2026-08-31'
    || $livEnded !== null
    || ! str_contains($history, 'Bo Lab')
) {
    $fail('Ending an assignment did not keep the historical row.');
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
    BoardPage::place();
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
    BoardPage::place();
} catch (\RuntimeException $error) {
    $capabilityFailed = $error->getMessage() === 'wp_die';
}

remove_all_filters('wp_die_handler');
wp_set_current_user(1);
clean_user_cache(1);

if (! $nonceFailed || ! $capabilityFailed) {
    $fail('Board changes did not require a nonce and manage_board.');
}

$cleanup();
\WP_CLI::success('Board admin can replace a treasurer without destroying history.');
