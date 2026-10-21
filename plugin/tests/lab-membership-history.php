<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\MemberCountBlock;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$tables = lab_membership_tables();
$people = $wpdb->prefix . 'assoc_person';
$cleanup = static function () use ($wpdb, $people): void {
    lab_delete_membership_numbers('LAB-HIST-%');

    foreach (['karin-hist@example.test', 'lisa-hist@example.test'] as $email) {
        $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", $email));

        if (is_numeric($personId)) {
            $wpdb->delete($wpdb->prefix . 'assoc_personal_identity', ['person_id' => (int) $personId], ['%d']);
            $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
        }
    }
};
$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$missingStarts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['participant']} WHERE started_on IS NULL");
$indexes = $wpdb->get_results("SHOW INDEX FROM {$tables['participant']} WHERE Key_name = 'membership_person'", ARRAY_A);
$unique = false;

if (is_array($indexes)) {
    foreach ($indexes as $index) {
        if (is_array($index) && (int) $index['Non_unique'] === 0) {
            $unique = true;
        }
    }
}

if ($missingStarts !== 0 || $unique) {
    $fail('Participation rows are missing a start date or still block a later join.');
}

$cleanup();
wp_set_current_user(1);
$service = WordpressPeople::service();
$service->register('Karin', 'Hist', 'karin-hist@example.test', 'LAB-HIST-F', 'family', AssociationDate::fromIso('2024-01-01'));
$accountId = 0;

foreach ($service->listPeople() as $record) {
    if ($record->membershipNumber() === 'LAB-HIST-F') {
        $accountId = (int) $record->account()?->id();
    }
}

// Other lab members can start or end between these dates, so each date is
// compared with its own count from before Lisa joins.
$countOn = static fn (string $date): int => $service->publicMemberCount(AssociationDate::fromIso($date));
$dates = ['2025-06-01', '2026-09-23', '2026-09-24'];
$before = array_combine($dates, array_map($countOn, $dates));
$lisa = $service->addPersonToMembership($accountId, 'Lisa', 'Hist', 'lisa-hist@example.test', AssociationDate::fromIso('2012-04-17'), AssociationDate::fromIso('2026-09-24'), \Foreningssystem\Domain\Membership\ParticipantRole::Member, false, AssociationDate::fromIso('2026-09-24'));
$after = array_combine($dates, array_map($countOn, $dates));

if (
    $accountId < 1
    || $after['2025-06-01'] !== $before['2025-06-01']
    || $after['2026-09-23'] !== $before['2026-09-23']
    || $after['2026-09-24'] !== $before['2026-09-24'] + 1
) {
    $fail('A participant who joins later was counted before the start date.');
}

$service->endParticipation($accountId, $lisa, AssociationDate::fromIso('2027-01-01'));
$service->addParticipant($accountId, $lisa, \Foreningssystem\Domain\Membership\ParticipantRole::Member, false, AssociationDate::fromIso('2028-01-01'));
$intervals = $wpdb->get_results($wpdb->prepare(
    "SELECT started_on, ended_on FROM {$tables['participant']} WHERE membership_id = %d AND person_id = %d ORDER BY started_on",
    $accountId,
    $lisa
), ARRAY_A);

if (
    ! is_array($intervals)
    || count($intervals) !== 2
    || ($intervals[0]['started_on'] ?? '') !== '2026-09-24'
    || ($intervals[0]['ended_on'] ?? '') !== '2027-01-01'
    || ($intervals[1]['started_on'] ?? '') !== '2028-01-01'
) {
    $fail('Rejoining a membership did not keep the earlier participation.');
}

if (current_user_can(Capabilities::EDIT_PERSONAL_IDENTITY_NUMBERS)) {
    WordpressPeople::identity()->store($lisa, '20120417-0011', 'Association administration', 'Association policy', AssociationDate::fromIso('2026-09-24'), AssociationDate::fromIso('2026-09-24'), 1);
}

wp_set_current_user(0);
$shown = MemberCountBlock::render();

if (str_contains($shown, '20120417-0011') || str_contains($shown, '2012••••-0011') || str_contains($shown, 'Lisa')) {
    $fail('The public member count exposed a person or a personal identity number.');
}

$cleanup();
\WP_CLI::success('Participation dates bound the member count, and a later join keeps the earlier interval.');
