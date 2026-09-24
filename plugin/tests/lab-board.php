<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\WordpressBoard;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$memberships = $wpdb->prefix . 'assoc_membership';
$assignments = $wpdb->prefix . 'assoc_board_assignment';
$roles = $wpdb->prefix . 'assoc_board_role';
$emails = ['lab-board-ada@example.test', 'lab-board-grace@example.test'];

foreach ($emails as $email) {
    $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

    if ($personId) {
        $wpdb->delete($assignments, ['person_id' => (int) $personId], ['%d']);
        $wpdb->delete($memberships, ['person_id' => (int) $personId], ['%d']);
        $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
    }
}

$existingOffice = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", 'lab_office'));

if ($existingOffice) {
    $wpdb->delete($assignments, ['role_id' => (int) $existingOffice], ['%d']);
    $wpdb->delete($roles, ['id' => (int) $existingOffice], ['%d']);
}

$insertedOffice = $wpdb->insert($roles, [
    'slug' => 'lab_office',
    'name' => 'Labbfunktion',
    'allows_multiple' => 0,
    'sort_order' => 900,
], ['%s', '%s', '%d', '%d']);

if ($insertedOffice === false) {
    \WP_CLI::error('The lab office role could not be created.');
}

$officeId = (int) $wpdb->insert_id;

wp_set_current_user(1);
$peopleService = WordpressPeople::service();
$board = WordpressBoard::service();
$ada = $peopleService->register('Ada', 'Lab', 'lab-board-ada@example.test', 'LAB-BOARD-1', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$grace = $peopleService->register('Grace', 'Lab', 'lab-board-grace@example.test', 'LAB-BOARD-2', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$chair = null;
$auditor = null;
$alternate = null;

foreach ($board->roles() as $role) {
    if ($role->slug() === 'chair') {
        $chair = $role;
    }
    if ($role->slug() === 'auditor') {
        $auditor = $role;
    }
    if ($role->slug() === 'alternate') {
        $alternate = $role;
    }
}

if ($chair === null || $auditor === null || $alternate === null || ! $auditor->allowsMultiple() || $chair->allowsMultiple()) {
    \WP_CLI::error('Suggested board roles were not seeded.');
}

$board->place($ada, $officeId, AssociationDate::fromIso('2024-01-01'), null, 'ordf@example.test', '2024');
$replaced = $board->place($grace, $officeId, AssociationDate::fromIso('2024-06-01'), null, '', '');

if ($replaced !== 'replaced') {
    \WP_CLI::error('Replacing the chair did not keep the previous assignment.');
}

$board->place($ada, (int) $auditor->id(), AssociationDate::fromIso('2024-01-01'), null, '', '');
$board->place($grace, (int) $auditor->id(), AssociationDate::fromIso('2024-01-01'), null, '', '');

$secretary = get_user_by('login', 'lab-secretary');

if (! $secretary instanceof WP_User) {
    \WP_CLI::error('Lab secretary is missing.');
}

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$denied = false;

try {
    $board->place($grace, (int) $alternate->id(), AssociationDate::fromIso('2024-02-01'), null, '', '');
} catch (NotAllowed) {
    $denied = true;
}

if (! $denied) {
    \WP_CLI::error('A secretary without manage_board could assign a role.');
}

$chairUser = get_user_by('login', 'lab-chair');

if (! $chairUser instanceof WP_User) {
    \WP_CLI::error('Lab chair is missing.');
}

clean_user_cache($chairUser->ID);
wp_set_current_user($chairUser->ID);
$board->place($grace, (int) $alternate->id(), AssociationDate::fromIso('2024-02-01'), null, 'suppleant@example.test', '');

wp_set_current_user(1);
$adaMembership = null;

foreach ($peopleService->listPeople() as $record) {
    if ($record->person()->id() === $ada) {
        $adaMembership = $record->membership()?->id();
    }
}

if ($adaMembership === null) {
    \WP_CLI::error('Ada has no membership to end.');
}

$peopleService->endMembership($adaMembership, AssociationDate::fromIso('2024-08-01'));
$adaChairEnded = null;
$adaAuditorEnded = null;
$graceStillCurrent = false;

foreach ($board->history(AssociationDate::fromIso('2024-07-01')) as $post) {
    $assignment = $post->assignment();

    if ($assignment->personId() === $ada && $post->roleSlug() === 'lab_office') {
        $adaChairEnded = $assignment->endedOn()?->iso();

        if ($assignment->publicContact() !== 'ordf@example.test') {
            \WP_CLI::error('The public role contact was replaced with the private email.');
        }
    }

    if ($assignment->personId() === $ada && $post->roleSlug() === 'auditor') {
        $adaAuditorEnded = $assignment->endedOn()?->iso();
    }

    if ($assignment->personId() === $grace && $post->roleSlug() === 'auditor' && $post->current()) {
        $graceStillCurrent = true;
    }

    if (str_contains($post->personName(), '@')) {
        \WP_CLI::error('The board list includes an email address in the person name.');
    }
}

if ($adaChairEnded !== '2024-05-31' || $adaAuditorEnded !== '2024-08-01' || ! $graceStillCurrent) {
    \WP_CLI::error('Ending the membership did not keep board history on the right dates.');
}

foreach ($emails as $email) {
    $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

    if ($personId) {
        $wpdb->delete($assignments, ['person_id' => (int) $personId], ['%d']);
        $wpdb->delete($memberships, ['person_id' => (int) $personId], ['%d']);
        $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
    }
}

$wpdb->delete($roles, ['slug' => 'lab_office'], ['%s']);

\WP_CLI::success('Board assignments follow the membership dates.');
