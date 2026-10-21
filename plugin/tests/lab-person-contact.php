<?php

/**
 * Street address, postal code, city and phone on a person, against the real database:
 * schema 18 columns, save and read back, the members screen, search, the WordPress
 * privacy exporter, and erase. See ADR-0035.
 */

use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\ContactDetails;
use Foreningssystem\Infrastructure\Persistence\PersonContactSchemaMigration;
use Foreningssystem\Infrastructure\WordPress\MembersScreen;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;
use Foreningssystem\Infrastructure\WordPress\WpdbPersonRepository;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$audit = $wpdb->prefix . 'assoc_audit_event';
$email = 'lab-contact@example.test';
$number = 'LAB-CONTACT-1';

$cleanup = static function () use ($wpdb, $people, $audit, $email, $number): void {
    $membership = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}assoc_membership WHERE membership_number = %s", $number));

    if ($membership > 0) {
        $wpdb->delete($wpdb->prefix . 'assoc_membership_period', ['membership_id' => $membership], ['%d']);
        $wpdb->delete($wpdb->prefix . 'assoc_membership_participant', ['membership_id' => $membership], ['%d']);
        $wpdb->delete($wpdb->prefix . 'assoc_membership', ['id' => $membership], ['%d']);
    }

    $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s OR last_name = %s", $email, 'Kontaktlab'));

    foreach (is_array($ids) ? $ids : [] as $id) {
        $wpdb->delete($audit, ['object_type' => 'person', 'object_id' => (int) $id], ['%s', '%d']);
        $wpdb->delete($people, ['id' => (int) $id], ['%d']);
    }
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();

if ((string) get_option('assoc_schema_version') !== '18') {
    $fail('Expected schema 18.');
}

$columns = [];

foreach ($wpdb->get_results("SHOW COLUMNS FROM {$people}", ARRAY_A) ?: [] as $column) {
    $columns[(string) $column['Field']] = strtolower((string) $column['Type']);
}

foreach (['street_address' => 'varchar(190)', 'postal_code' => 'varchar(20)', 'city' => 'varchar(100)', 'phone' => 'varchar(40)'] as $name => $type) {
    if (($columns[$name] ?? '') !== $type) {
        $fail('Column ' . $name . ' should be ' . $type . '. Found: ' . wp_json_encode($columns));
    }
}

// Running the migration again on a database that has the columns changes nothing.
(new PersonContactSchemaMigration($wpdb->prefix))->up();

wp_set_current_user(1);
$today = AssociationDate::fromIso(wp_date('Y-m-d'));
$service = WordpressPeople::service();
$personId = $service->register(
    'Åsa',
    'Kontaktlab',
    $email,
    $number,
    'ordinary',
    AssociationDate::fromIso('2024-01-01'),
    null,
    $today,
    new ContactDetails('Ängsvägen 12 <b>', '582 22', 'Linköping', '+46 70-123 45 03')
);
$stored = (new WpdbPersonRepository())->find($personId)?->contactDetails();

if ($stored === null || [$stored->streetAddress(), $stored->postalCode(), $stored->city(), $stored->phone()] !== ['Ängsvägen 12 <b>', '582 22', 'Linköping', '+46 70-123 45 03']) {
    $fail('The address and phone were not stored as given.');
}

// The detail view escapes the stored text.
set_current_screen('dashboard');
$_GET = ['page' => 'foreningsplugin-members', 'assoc_person' => (string) $personId];
ob_start();
MembersScreen::render(true);
$html = (string) ob_get_clean();

if (! str_contains($html, 'value="Ängsvägen 12 &lt;b&gt;"') || str_contains($html, 'Ängsvägen 12 <b>') || ! str_contains($html, 'name="phone"')) {
    $fail('The person form should show the escaped address and a phone field.');
}

// City and phone are searchable, the phone also without spaces and dashes.
foreach (['linköping', '701234503', '070-123'] as $query) {
    $found = array_column(WordpressPeople::directory()->listRows($query === '070-123' ? '70-123' : $query, '', '', $today), 'person_id');

    if (! in_array($personId, $found, true)) {
        $fail('Search for "' . $query . '" should find the person.');
    }
}

// An edit records which part changed and keeps values out of the audit row.
$service->updateContact($personId, 'Åsa', 'Kontaktlab', $email, null, $today, 1, null, new ContactDetails('Ängsvägen 14', '582 22', 'Linköping', '+46 70-123 45 03'));
$actions = $wpdb->get_col($wpdb->prepare("SELECT action FROM {$audit} WHERE object_type = 'person' AND object_id = %d ORDER BY id", $personId));

if ($actions !== ['contact_address_changed']) {
    $fail('An address edit should leave one contact_address_changed event. Found: ' . wp_json_encode($actions));
}

// The WordPress privacy exporter lists address and phone.
$export = apply_filters('wp_privacy_personal_data_exporters', []);
$exported = '';

foreach ($export as $exporter) {
    if (is_array($exporter) && isset($exporter['callback']) && is_callable($exporter['callback'])) {
        $exported .= wp_json_encode(call_user_func($exporter['callback'], $email, 1), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (! str_contains($exported, 'Ängsvägen 14') || ! str_contains($exported, '+46 70-123 45 03')) {
    $fail('The privacy export should contain the address and phone.');
}

// Erase clears the columns in the database.
$erasers = apply_filters('wp_privacy_personal_data_erasers', []);

foreach ($erasers as $eraser) {
    if (is_array($eraser) && isset($eraser['callback']) && is_callable($eraser['callback'])) {
        call_user_func($eraser['callback'], $email, 1);
    }
}

$row = $wpdb->get_row($wpdb->prepare("SELECT street_address, postal_code, city, phone FROM {$people} WHERE id = %d", $personId), ARRAY_A);

if ($row !== ['street_address' => '', 'postal_code' => '', 'city' => '', 'phone' => '']) {
    $fail('Erase should clear address and phone. Found: ' . wp_json_encode($row));
}

$cleanup();
\WP_CLI::success('Address and phone are stored, escaped, searchable, exported and erased.');
