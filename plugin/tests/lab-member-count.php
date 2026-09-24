<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\MemberCountBlock;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$memberships = $wpdb->prefix . 'assoc_membership';

$cleanup = static function () use ($wpdb, $people, $memberships): void {
    $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", 'lab-count@example.test'));

    if ($personId) {
        lab_delete_person_memberships((int) $personId);
        $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
    }

    $wpdb->query($wpdb->prepare("DELETE FROM {$memberships} WHERE membership_number = %s", 'LAB-COUNT-1'));
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();
wp_set_current_user(1);
$service = WordpressPeople::service();
$today = AssociationDate::fromIso(wp_date('Y-m-d'));
$before = $service->publicMemberCount($today);
$personId = $service->register('Räkne', 'Lab', 'lab-count@example.test', 'LAB-COUNT-1', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$during = $service->publicMemberCount($today);

wp_set_current_user(0);
$shown = MemberCountBlock::render();

if (
    $during !== $before + 1
    || ! str_contains($shown, 'Aktiva enskilda medlemmar: ' . $during)
    || str_contains($shown, 'Räkne')
    || str_contains($shown, 'lab-count@example.test')
    || str_contains($shown, 'LAB-COUNT-1')
) {
    $fail('The public member count showed a name or the wrong number.');
}

wp_set_current_user(1);
$membershipId = null;

foreach ($service->listPeople() as $record) {
    if ($record->person()->id() === $personId) {
        $membershipId = $record->membership()?->id();
    }
}

if ($membershipId === null) {
    $fail('The counted membership was not saved.');
}

$service->endMembership($membershipId, AssociationDate::fromIso('2024-06-01'));
wp_set_current_user(0);
$after = MemberCountBlock::render();

if (! str_contains($after, 'Aktiva enskilda medlemmar: ' . $before) || str_contains($after, 'Räkne')) {
    $fail('Ending the membership left the person in the public count.');
}

$cleanup();
\WP_CLI::success('The member count block shows the number of active members.');
