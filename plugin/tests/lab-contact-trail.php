<?php

/**
 * A contact edit leaves one value-free audit event per changed part, in the same
 * transaction as the edit. Export lists it, erase keeps it, retention removes it. See ADR-0032.
 */

use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;
use Foreningssystem\Infrastructure\WordPress\WordpressPrivacy;
use Foreningssystem\Infrastructure\WordPress\WordpressRetention;
use Foreningssystem\Infrastructure\WordPress\WpdbPersonRepository;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$audit = $wpdb->prefix . 'assoc_audit_event';
$parked = $wpdb->prefix . 'assoc_audit_event_lab_parked';
$emails = ['lab-trail-old@example.test', 'lab-trail@example.test'];

$cleanup = static function () use ($wpdb, $people, $audit, $parked, $emails): void {
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $parked)) === $parked) {
        $wpdb->query("RENAME TABLE {$parked} TO {$audit}");
    }

    $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$people} WHERE last_name = %s OR email IN (%s, %s)", 'Spår', $emails[0], $emails[1]));

    foreach (is_array($ids) ? $ids : [] as $id) {
        $wpdb->delete($audit, ['object_type' => 'person', 'object_id' => (int) $id], ['%s', '%d']);
        $wpdb->delete($people, ['id' => (int) $id], ['%d']);
    }
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

/**
 * @return list<array{action: string, actor_user_id: string, row: string}>
 */
$events = static function (int $personId) use ($wpdb, $audit): array {
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$audit} WHERE object_type = 'person' AND object_id = %d ORDER BY id", $personId), ARRAY_A);

    return array_map(static fn (array $row): array => [
        'action' => (string) $row['action'],
        'actor_user_id' => (string) $row['actor_user_id'],
        'row' => implode('|', array_map('strval', $row)),
    ], is_array($rows) ? $rows : []);
};

$cleanup();
wp_set_current_user(1);
$service = WordpressPeople::service();
$today = AssociationDate::fromIso(wp_date('Y-m-d'));
$personId = $service->rememberPerson('Lab', 'Spår', 'lab-trail-old@example.test', AssociationDate::fromIso('1990-01-01'), $today);

// One changed part, one event, with the officer and without the value.
$service->updateContact($personId, 'Lab', 'Spår', 'lab-trail@example.test', AssociationDate::fromIso('1990-01-01'), $today, 1);
$found = $events($personId);

if (count($found) !== 1 || $found[0]['action'] !== 'contact_email_changed' || $found[0]['actor_user_id'] !== '1') {
    $fail('An email change should leave one contact_email_changed event by user 1. Found: ' . wp_json_encode($found));
}

foreach (['lab-trail', 'Spår', '1990'] as $value) {
    if (str_contains($found[0]['row'], $value)) {
        $fail("The audit row holds a contact value ({$value}).");
    }
}

// A save without a change writes nothing.
$service->updateContact($personId, 'Lab', 'Spår', 'lab-trail@example.test', AssociationDate::fromIso('1990-01-01'), $today, 1);

if (count($events($personId)) !== 1) {
    $fail('A save without a change should write no event.');
}

// The event is part of the edit: if it cannot be written, the edit is rolled back.
$wpdb->query("RENAME TABLE {$audit} TO {$parked}");
$suppress = $wpdb->suppress_errors(true);
$refused = false;

try {
    $service->updateContact($personId, 'Lab', 'Ändrad', 'lab-trail@example.test', AssociationDate::fromIso('1990-01-01'), $today, 1);
} catch (\RuntimeException) {
    $refused = true;
} finally {
    $wpdb->suppress_errors($suppress);
    $wpdb->query("RENAME TABLE {$parked} TO {$audit}");
}

$stored = (new WpdbPersonRepository())->find($personId);

if (! $refused || $stored?->lastName() !== 'Spår') {
    $fail('A contact edit whose audit event fails must not be saved.');
}

// Name and birth date in one save: two events.
$service->updateContact($personId, 'Lab', 'Spår', 'lab-trail@example.test', AssociationDate::fromIso('1991-02-03'), $today, 1);
$service->updateContact($personId, 'Labb', 'Spår', 'lab-trail@example.test', AssociationDate::fromIso('1991-02-03'), $today, 1);
$actions = array_column($events($personId), 'action');

if ($actions !== ['contact_email_changed', 'contact_birth_date_changed', 'contact_name_changed']) {
    $fail('Unexpected contact events: ' . wp_json_encode($actions));
}

// The WordPress exporter lists the changes as day and part, without the officer.
$export = WordpressPrivacy::export('lab-trail@example.test', 1);
$changes = array_values(array_filter($export['data'], static fn (array $item): bool => $item['group_id'] === 'foreningsplugin-contact-changes'));

if (count($changes) !== 3 || $changes[0]['data'][0]['value'] !== substr((string) current_time('mysql', true), 0, 10)) {
    $fail('The export should list three contact changes with their day. Found: ' . wp_json_encode($changes));
}

// Erase anonymizes the person and keeps the value-free events.
WordpressPrivacy::erase('lab-trail@example.test', 1);
$actions = array_column($events($personId), 'action');

if ((new WpdbPersonRepository())->find($personId)?->email() !== '' || $actions !== ['contact_email_changed', 'contact_birth_date_changed', 'contact_name_changed', 'export_personal_data', 'anonymize_person']) {
    $fail('Erase should anonymize the person and keep the contact events. Found: ' . wp_json_encode($actions));
}

// Retention removes a contact event older than the setting, like any other audit event.
$first = (int) $wpdb->get_var($wpdb->prepare("SELECT MIN(id) FROM {$audit} WHERE object_type = 'person' AND object_id = %d", $personId));
$wpdb->update($audit, ['created_at' => gmdate('Y-m-d H:i:s', strtotime('-20 years'))], ['id' => $first], ['%s'], ['%d']);
WordpressRetention::applyToday();
$actions = array_column($events($personId), 'action');

if ($actions !== ['contact_birth_date_changed', 'contact_name_changed', 'export_personal_data', 'anonymize_person']) {
    $fail('Retention should remove the old contact event and keep the newer ones. Found: ' . wp_json_encode($actions));
}

$cleanup();
\WP_CLI::success('A contact edit leaves value-free events that export lists, erase keeps, and retention removes.');
