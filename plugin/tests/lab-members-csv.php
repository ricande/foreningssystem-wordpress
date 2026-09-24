<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Infrastructure\WordPress\MembersPage;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

require __DIR__ . '/lab-membership.php';

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$memberships = $wpdb->prefix . 'assoc_membership';

$cleanup = static function () use ($wpdb, $people): void {
    lab_delete_membership_numbers('LAB-CSV-%');
    $wpdb->query($wpdb->prepare("DELETE FROM {$people} WHERE email = %s", 'ada-csv@example.test'));
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$csv = <<<'CSV'
first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on
Åsa;Lund;ada-csv@example.test;known;LAB-CSV-1;ordinarie;ended;2020-01-01;2021-12-31
Annat;Namn;ada-csv@example.test;known;LAB-CSV-2;ordinarie;active;2024-01-01;
CSV;

$cleanup();
$secretary = get_user_by('login', 'lab-secretary');

if (! $secretary instanceof WP_User) {
    \WP_CLI::error('Missing lab user lab-secretary');
}

clean_user_cache((int) $secretary->ID);
wp_set_current_user((int) $secretary->ID);
$exportDenied = false;
$importDenied = false;

try {
    WordpressPeople::exchange()->export();
} catch (NotAllowed $error) {
    $exportDenied = $error->getMessage() === Capabilities::EXPORT_MEMBERS;
}

try {
    WordpressPeople::exchange()->import($csv);
} catch (NotAllowed $error) {
    $importDenied = $error->getMessage() === Capabilities::EDIT_MEMBERS;
}

wp_set_current_user(1);
clean_user_cache(1);
$imported = WordpressPeople::exchange()->import($csv);
$repeated = WordpressPeople::exchange()->import(str_replace('2021-12-31', '2099-01-01', $csv));
$exported = WordpressPeople::exchange()->export();
$personCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$people} WHERE email = %s", 'ada-csv@example.test'));
$period = $wpdb->get_row($wpdb->prepare(
    "SELECT period.status, period.ended_on
     FROM {$wpdb->prefix}assoc_membership_period period
     INNER JOIN {$memberships} membership ON membership.id = period.membership_id
     WHERE membership.membership_number = %s",
    'LAB-CSV-1'
), ARRAY_A);
$name = (string) $wpdb->get_var($wpdb->prepare("SELECT first_name FROM {$people} WHERE email = %s", 'ada-csv@example.test'));
ob_start();
MembersPage::render();
$html = (string) ob_get_clean();

if (
    $exportDenied !== true
    || $importDenied !== true
    || $imported->created() !== 2
    || $imported->errors() !== []
    || $repeated->created() !== 0
    || $repeated->skipped() !== 2
    || $personCount !== 1
    || $name !== 'Åsa'
    || ! is_array($period)
    || (string) $period['status'] !== MembershipStatus::Ended->value
    || (string) $period['ended_on'] !== '2021-12-31'
    || ! str_contains($exported, 'LAB-CSV-1')
    || ! str_contains($exported, 'LAB-CSV-2')
    || ! str_contains(strtok(ltrim($exported, "\xEF\xBB\xBF"), "\n") ?: '', 'membership_number')
    || str_contains(strtok(ltrim($exported, "\xEF\xBB\xBF"), "\n") ?: '', 'wp_user')
    || ! str_contains($html, 'Importera medlemmar')
    || ! str_contains($html, 'Exportera medlemmar')
    || ! str_contains($html, 'assoc_export_members')
) {
    $fail('Member import changed an existing period or created a second person for the same email.');
}

$cleanup();
$left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$memberships} WHERE membership_number LIKE %s", 'LAB-CSV-%'));

if ($left !== 0) {
    \WP_CLI::error('The imported lab memberships were not removed.');
}

\WP_CLI::success('Member import adds periods and leaves an existing membership number unchanged.');
