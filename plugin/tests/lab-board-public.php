<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\PersonStatus;
use Foreningssystem\Infrastructure\WordPress\CurrentBoardBlock;
use Foreningssystem\Infrastructure\WordPress\WordpressBoard;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$memberships = $wpdb->prefix . 'assoc_membership';
$assignments = $wpdb->prefix . 'assoc_board_assignment';
$roles = $wpdb->prefix . 'assoc_board_role';
$emails = ['lab-public-ada@example.test', 'lab-public-kim@example.test', 'lab-public-nio@example.test'];

$cleanup = static function () use ($wpdb, $people, $memberships, $assignments, $roles, $emails): void {
    foreach ($emails as $email) {
        $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

        if ($personId) {
            $wpdb->delete($assignments, ['person_id' => (int) $personId], ['%d']);
            lab_delete_person_memberships((int) $personId);
            $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
        }
    }

    $wpdb->delete($roles, ['slug' => 'lab_public_seat'], ['%s']);
    $wpdb->delete($roles, ['slug' => 'lab_public_extra'], ['%s']);
};

$cleanup();

if ($wpdb->insert($roles, [
    'slug' => 'lab_public_seat',
    'name' => 'Labbstol',
    'allows_multiple' => 0,
    'sort_order' => 15,
], ['%s', '%s', '%d', '%d']) === false) {
    \WP_CLI::error('The public lab role could not be created.');
}

$seatId = (int) $wpdb->insert_id;

if ($wpdb->insert($roles, [
    'slug' => 'lab_public_extra',
    'name' => 'Labbrevisor',
    'allows_multiple' => 1,
    'sort_order' => 80,
], ['%s', '%s', '%d', '%d']) === false) {
    $cleanup();
    \WP_CLI::error('The extra public lab role could not be created.');
}

$extraId = (int) $wpdb->insert_id;
wp_set_current_user(1);
$peopleService = WordpressPeople::service();
$board = WordpressBoard::service();
$ada = $peopleService->register('Ada', 'Lab', 'lab-public-ada@example.test', 'LAB-PUBLIC-1', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$kim = $peopleService->register('Kim', 'Lab <X>', 'lab-public-kim@example.test', 'LAB-PUBLIC-2', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$nio = $peopleService->register('Nio', 'Lab', 'lab-public-nio@example.test', 'LAB-PUBLIC-3', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$board->place($ada, $seatId, AssociationDate::fromIso('2024-01-01'), null, 'gammal-styrelse@example.test', '');
$board->place($kim, $seatId, AssociationDate::fromIso('2024-06-01'), null, 'styrelse@example.test', '');
$board->place($nio, $extraId, AssociationDate::fromIso('2024-01-01'), null, 'nio@example.test', '');
$peopleService->markDeceased($nio, AssociationDate::fromIso('2024-08-01'));

$nioRow = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$people} WHERE id = %d", $nio), ARRAY_A);

if (! is_array($nioRow) || (string) $nioRow['status'] !== PersonStatus::Deceased->value) {
    $cleanup();
    \WP_CLI::error('The deceased person was not marked.');
}

wp_set_current_user(0);
$html = CurrentBoardBlock::render();

if (
    ! str_contains($html, 'Kim Lab &lt;X&gt;')
    || str_contains($html, 'Kim Lab <X>')
    || ! str_contains($html, 'Labbstol')
    || ! str_contains($html, 'styrelse@example.test')
    || str_contains($html, 'lab-public-ada@example.test')
    || str_contains($html, 'lab-public-kim@example.test')
    || str_contains($html, 'lab-public-nio@example.test')
    || str_contains($html, 'gammal-styrelse@example.test')
    || str_contains($html, 'Nio Lab')
    || str_contains($html, 'Ada Lab')
) {
    $cleanup();
    \WP_CLI::error('The public board showed a private email, a replaced officer, or a deceased person.');
}

$cleanup();
\WP_CLI::success('The current board block shows name, role, and public contact.');
