<?php

use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

require __DIR__ . '/lab-membership.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$tables = lab_membership_tables();
$emails = ['ada-trip@example.test', 'bo-trip@example.test', 'dag-trip@example.test', 'asa-trip@example.test', 'formel-trip@example.test'];

$cleanup = static function () use ($wpdb, $people, $tables, $emails): void {
    $personIds = $wpdb->get_col($wpdb->prepare(
        "SELECT participant.person_id
         FROM {$tables['participant']} participant
         INNER JOIN {$tables['membership']} membership ON membership.id = participant.membership_id
         WHERE membership.membership_number LIKE %s",
        'LAB-TRIP-%'
    ));
    lab_delete_membership_numbers('LAB-TRIP-%');

    foreach (is_array($personIds) ? $personIds : [] as $personId) {
        $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
    }

    foreach ($emails as $email) {
        $wpdb->delete($people, ['email' => $email], ['%s']);
        $user = get_user_by('email', $email);

        if ($user instanceof WP_User) {
            wp_delete_user((int) $user->ID);
        }
    }
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

/**
 * The LAB-TRIP records of a structured file, as parsed cells, in file order.
 *
 * @return list<list<string>>
 */
$records = static function (string $csv): array {
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, ltrim($csv, "\xEF\xBB\xBF"));
    rewind($handle);
    $found = [];

    while (($cells = fgetcsv($handle, 0, ';', '"', '')) !== false) {
        if (isset($cells[1]) && str_starts_with((string) $cells[1], 'LAB-TRIP-')) {
            $found[] = array_map('strval', $cells);
        }
    }

    fclose($handle);

    return $found;
};

// Synthetic people only. Å splits a byte-wise line reader, ';' and '"' need quoting, '=' needs the formula guard.
$file = <<<'CSV'
# foreningsplugin-members 2
membership;LAB-TRIP-1;ordinary;
period;LAB-TRIP-1;ended;2019-01-01;2020-12-31;ordinarie
period;LAB-TRIP-1;active;2023-01-01;;ordinary
participant;LAB-TRIP-1;Ada;Återkommer;ada-trip@example.test;;member;1;2019-01-01;2020-12-31;known
participant;LAB-TRIP-1;Ada;Återkommer;ada-trip@example.test;;member;0;2023-01-01;;known
membership;LAB-TRIP-2;family;
period;LAB-TRIP-2;active;2022-01-01;;family
participant;LAB-TRIP-2;Bo;Familj;bo-trip@example.test;;member;1;2022-01-01;;known
participant;LAB-TRIP-2;Cia;Familj;;2015-04-02;member;0;2023-05-01;;known
membership;LAB-TRIP-3;ordinary;
period;LAB-TRIP-3;ended;2018-01-01;2024-03-01;ordinary
participant;LAB-TRIP-3;Dag;Avliden;dag-trip@example.test;;member;1;2018-01-01;2024-03-01;deceased
membership;LAB-TRIP-4;ordinary;
period;LAB-TRIP-4;active;2024-01-01;;"Årsavgift; full"
participant;LAB-TRIP-4;"Åsa;Maria";"Öberg ""Ö""";asa-trip@example.test;;member;1;2024-01-01;;known
membership;LAB-TRIP-5;ordinary;
period;LAB-TRIP-5;active;2024-01-01;;ordinary
participant;LAB-TRIP-5;'=SUM(A1);Ärlig\;formel-trip@example.test;;member;1;2024-01-01;;known
CSV;

$cleanup();
wp_set_current_user(1);
clean_user_cache(1);

$first = WordpressPeople::exchange()->import($file);
$exported = WordpressPeople::exchange()->exportStructure();
$expected = $records($file);
$afterFirst = $records($exported);

if ($first->errors() !== [] || $afterFirst !== $expected) {
    $fail('The structured member file did not come back as it went in: ' . wp_json_encode([$first->errors(), $afterFirst]));
}

$deceased = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$people} WHERE email = %s", 'dag-trip@example.test'));
$name = $wpdb->get_row($wpdb->prepare("SELECT first_name, last_name FROM {$people} WHERE email = %s", 'asa-trip@example.test'), ARRAY_A);
$formula = (string) $wpdb->get_var($wpdb->prepare("SELECT first_name FROM {$people} WHERE email = %s", 'formel-trip@example.test'));

if (
    $deceased !== 'deceased'
    || ! is_array($name)
    || $name['first_name'] !== 'Åsa;Maria'
    || $name['last_name'] !== 'Öberg "Ö"'
    || $formula !== '=SUM(A1)'
) {
    $fail('The structured member file changed a status or a name in the database.');
}

$repeated = WordpressPeople::exchange()->import($exported);

if ($repeated->created() !== 0 || $records(WordpressPeople::exchange()->exportStructure()) !== $expected) {
    $fail('Importing the exported structured file again changed the register.');
}

// Empty the LAB-TRIP part of the register and bring it back from the export.
$cleanup();
$subset = "# foreningsplugin-members 2\n" . implode("\n", array_filter(
    explode("\n", ltrim($exported, "\xEF\xBB\xBF")),
    static fn (string $line): bool => preg_match('/^[a-z]+;LAB-TRIP-/', $line) === 1
)) . "\n";
$restored = WordpressPeople::exchange()->import($subset);
$afterRestore = $records(WordpressPeople::exchange()->exportStructure());
$restoredDeceased = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$people} WHERE email = %s", 'dag-trip@example.test'));
$children = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$people} WHERE first_name = %s AND last_name = %s", 'Cia', 'Familj'));

if ($restored->errors() !== [] || $afterRestore !== $expected || $restoredDeceased !== 'deceased' || $children !== 1) {
    $fail('The structured export did not restore the same people, periods and participants: ' . wp_json_encode($restored->errors()));
}

// The plain file keeps one period and one member per membership, and says so for the rest.
$plain = WordpressPeople::exchange()->export();
$plainLines = array_values(array_filter(
    explode("\n", ltrim($plain, "\xEF\xBB\xBF")),
    static fn (string $line): bool => str_contains($line, 'LAB-TRIP-')
));
$cleanup();
$plainResult = WordpressPeople::exchange()->import(
    "first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on\n"
    . implode("\n", $plainLines) . "\n"
);
$notImported = array_values(array_filter(
    $plainResult->errors(),
    static fn (string $error): bool => str_contains($error, 'Not imported.') && str_contains($error, 'structured member file')
));

if (
    count($plainLines) !== 7
    || $plainResult->created() !== 5
    || count($notImported) !== 2
    || count($plainResult->errors()) !== 2
) {
    $fail('The plain member file did not report later periods and family members: ' . wp_json_encode($plainResult->errors()));
}

$cleanup();
$left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['membership']} WHERE membership_number LIKE %s", 'LAB-TRIP-%'));

if ($left !== 0) {
    \WP_CLI::error('The lab round-trip memberships were not removed.');
}

\WP_CLI::success('The structured member file restores people, periods, participants and status; the plain file reports what it leaves out.');
