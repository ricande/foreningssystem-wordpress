<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\PersonStatus;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$memberships = $wpdb->prefix . 'assoc_membership';
$wpdb->query($wpdb->prepare("DELETE FROM {$memberships} WHERE membership_number = %s", 'LAB-PERSON-1'));
$wpdb->query($wpdb->prepare("DELETE FROM {$people} WHERE email = %s", 'lab-person@example.test'));

$service = WordpressPeople::service();
wp_set_current_user(1);

$personId = $service->register(
    'Lab',
    'Person',
    'lab-person@example.test',
    'LAB-PERSON-1',
    'ordinarie',
    AssociationDate::fromIso('2024-01-01')
);

$duplicateRejected = false;

try {
    $service->register('Annan', 'Person', 'annan-lab@example.test', 'LAB-PERSON-1', 'ordinarie', AssociationDate::fromIso('2024-02-01'));
} catch (MembershipRuleException) {
    $duplicateRejected = true;
}

if (! $duplicateRejected) {
    \WP_CLI::error('Duplicate membership number was accepted.');
}

$membershipId = null;

foreach ($service->listPeople() as $record) {
    if ($record->person()->id() === $personId) {
        $membershipId = $record->membership()?->id();
    }
}

if ($membershipId === null) {
    \WP_CLI::error('The lab person has no membership.');
}

$service->endMembership($membershipId, AssociationDate::fromIso('2024-06-01'));
$ended = null;

foreach ($service->listPeople() as $record) {
    if ($record->person()->id() === $personId) {
        $ended = $record;
    }
}

if ($ended === null || $ended->membership()?->status() !== MembershipStatus::Ended) {
    \WP_CLI::error('Ending the membership removed the person or left the period open.');
}

$secretary = get_user_by('login', 'lab-secretary');

if (! $secretary instanceof WP_User) {
    \WP_CLI::error('Lab secretary is missing.');
}

wp_set_current_user($secretary->ID);
$denied = false;

try {
    $service->register('Otillaten', 'Person', 'otillaten@example.test', 'LAB-PERSON-2', 'ordinarie', AssociationDate::fromIso('2024-03-01'));
} catch (NotAllowed) {
    $denied = true;
}

if (! $denied) {
    \WP_CLI::error('A secretary without edit_members could register a person.');
}

wp_set_current_user(1);
$service->markDeceased($personId, AssociationDate::fromIso('2024-09-24'));
$deceased = null;

foreach ($service->listPeople() as $record) {
    if ($record->person()->id() === $personId) {
        $deceased = $record;
    }
}

if ($deceased === null || $deceased->person()->status() !== PersonStatus::Deceased) {
    \WP_CLI::error('The person was not kept after being marked deceased.');
}

if ($deceased->membership()?->status() !== MembershipStatus::Ended) {
    \WP_CLI::error('The membership disappeared when the person was marked deceased.');
}

$wpdb->query($wpdb->prepare("DELETE FROM {$memberships} WHERE membership_number = %s", 'LAB-PERSON-1'));
$wpdb->query($wpdb->prepare("DELETE FROM {$people} WHERE email = %s", 'lab-person@example.test'));

\WP_CLI::success('People and memberships keep their history.');
