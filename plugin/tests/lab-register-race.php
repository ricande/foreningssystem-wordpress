<?php
/**
 * Setup, check and cleanup for scripts/test-lab-race.sh.
 *
 * wp eval-file lab-register-race.php setup <scenario>
 * wp eval-file lab-register-race.php check <scenario> <id> <workers-json-file>
 * wp eval-file lab-register-race.php cleanup
 */

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$tables = lab_membership_tables();
$people = $wpdb->prefix . 'assoc_person';
$action = (string) ($args[0] ?? '');
$scenario = (string) ($args[1] ?? '');

$cleanup = static function () use ($wpdb, $people): void {
    lab_delete_membership_numbers('LAB-RACE-%');
    $wpdb->query($wpdb->prepare("DELETE FROM {$people} WHERE email LIKE %s", 'lab-race-%@example.test'));
};

if ($action === 'cleanup') {
    $cleanup();
    \WP_CLI::success('Race data removed.');

    return;
}

if ($action === 'setup') {
    $cleanup();
    wp_set_current_user(1);
    $service = WordpressPeople::service();

    if ($scenario === 'add_period') {
        $service->register('Race', 'Period', 'lab-race-period@example.test', 'LAB-RACE-PERIOD', 'ordinary', AssociationDate::fromIso('2024-01-01'));
        $membershipId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['membership']} WHERE membership_number = %s", 'LAB-RACE-PERIOD'));
        $periodId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['period']} WHERE membership_id = %d", $membershipId));
        $service->endMembership($periodId, AssociationDate::fromIso('2024-12-31'));
        echo $membershipId . "\n";

        return;
    }

    if ($scenario === 'update_contact') {
        $personId = $service->rememberPerson('Race', 'Contact', 'lab-race-contact@example.test', null, AssociationDate::fromIso('2025-01-01'));
        $person = (new \Foreningssystem\Infrastructure\WordPress\WpdbPersonRepository())->find($personId);
        echo $personId . ' ' . (string) $person?->contactVersion() . "\n";

        return;
    }

    echo "0\n";

    return;
}

if ($action !== 'check') {
    \WP_CLI::error('Unknown action.');
}

$target = (int) ($args[2] ?? 0);
$lines = file((string) ($args[3] ?? ''), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$results = [];

foreach (is_array($lines) ? $lines : [] as $line) {
    $decoded = json_decode($line, true);

    if (is_array($decoded)) {
        $results[] = $decoded;
    }
}

$fail = static function (string $message) use ($cleanup, $results): void {
    $cleanup();
    \WP_CLI::error($message . ' Workers: ' . json_encode($results));
};
$workers = (int) ($args[4] ?? 0);

if ($workers < 2 || count($results) !== $workers) {
    $fail('Every worker must report one result.');
}

$late = array_map(static fn (array $result): float => (float) $result['late'], $results);

if (min($late) < 0 || max($late) - min($late) > 0.5) {
    $fail('The workers did not start together, so the run proves nothing.');
}

$outcomes = array_count_values(array_map(static fn (array $result): string => (string) $result['outcome'], $results));
$saved = $outcomes['saved'] ?? 0;
$expected = match ($scenario) {
    'add_period' => 'refused:Membership periods cannot overlap.',
    'register' => 'refused:Membership number is already used.',
    'update_contact' => 'changed',
    default => '',
};

if ($saved !== 1 || ($outcomes[$expected] ?? 0) + ($outcomes['busy'] ?? 0) !== $workers - 1) {
    $fail('Exactly one worker should save and the others should be refused.');
}

if ($scenario === 'add_period') {
    $open = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['period']} WHERE membership_id = %d AND ended_on IS NULL", $target));
    $all = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['period']} WHERE membership_id = %d", $target));

    if ($open !== 1 || $all !== 2) {
        $fail("Expected one ended and one open period, found {$all} periods and {$open} open.");
    }
}

if ($scenario === 'register') {
    $accounts = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['membership']} WHERE membership_number = %s", 'LAB-RACE-NUMBER'));
    $persons = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$people} WHERE email LIKE %s", 'lab-race-number-%@example.test'));

    if ($accounts !== 1 || $persons !== 1) {
        $fail("Expected one membership and one person, found {$accounts} and {$persons}.");
    }
}

if ($scenario === 'update_contact') {
    $lastName = (string) $wpdb->get_var($wpdb->prepare("SELECT last_name FROM {$people} WHERE id = %d", $target));
    $winner = null;

    foreach ($results as $result) {
        if ($result['outcome'] === 'saved') {
            $winner = 'Contact ' . $result['worker'];
        }
    }

    if ($lastName !== $winner) {
        $fail("The stored name '{$lastName}' is not the one the saving worker wrote.");
    }
}

$cleanup();
\WP_CLI::success(sprintf('%s: 1 of %d parallel workers saved, the rest were refused (spread %.3fs).', $scenario, $workers, max($late) - min($late)));
