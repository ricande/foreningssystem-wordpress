<?php

function lab_membership_tables(): array
{
    global $wpdb;

    return [
        'membership' => $wpdb->prefix . 'assoc_membership',
        'period' => $wpdb->prefix . 'assoc_membership_period',
        'participant' => $wpdb->prefix . 'assoc_membership_participant',
    ];
}

function lab_delete_membership_numbers(string $like): void
{
    global $wpdb;

    $tables = lab_membership_tables();
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$tables['membership']} WHERE membership_number LIKE %s",
        $like
    ));

    if (! is_array($ids)) {
        return;
    }

    foreach ($ids as $id) {
        $membershipId = (int) $id;
        $wpdb->delete($tables['period'], ['membership_id' => $membershipId], ['%d']);
        $wpdb->delete($tables['participant'], ['membership_id' => $membershipId], ['%d']);
        $wpdb->delete($tables['membership'], ['id' => $membershipId], ['%d']);
    }
}

function lab_delete_person_memberships(int $personId): void
{
    global $wpdb;

    $tables = lab_membership_tables();
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT membership_id FROM {$tables['participant']} WHERE person_id = %d",
        $personId
    ));

    if (! is_array($ids)) {
        return;
    }

    foreach ($ids as $id) {
        $membershipId = (int) $id;
        $wpdb->delete($tables['period'], ['membership_id' => $membershipId], ['%d']);
        $wpdb->delete($tables['participant'], ['membership_id' => $membershipId], ['%d']);
        $wpdb->delete($tables['membership'], ['id' => $membershipId], ['%d']);
    }
}

function lab_person_id_for_membership_number(string $number): int
{
    global $wpdb;

    $tables = lab_membership_tables();
    $personId = $wpdb->get_var($wpdb->prepare(
        "SELECT participant.person_id
         FROM {$tables['participant']} participant
         INNER JOIN {$tables['membership']} membership ON membership.id = participant.membership_id
         WHERE membership.membership_number = %s
         ORDER BY participant.id ASC
         LIMIT 1",
        $number
    ));

    return is_numeric($personId) ? (int) $personId : 0;
}

function lab_period_for_person(int $personId): array
{
    global $wpdb;

    $tables = lab_membership_tables();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT membership.membership_number, period.status, period.started_on, period.ended_on
         FROM {$tables['period']} period
         INNER JOIN {$tables['membership']} membership ON membership.id = period.membership_id
         INNER JOIN {$tables['participant']} participant ON participant.membership_id = membership.id
         WHERE participant.person_id = %d
         ORDER BY period.started_on DESC, period.id DESC
         LIMIT 1",
        $personId
    ), ARRAY_A);

    return is_array($row) ? $row : [];
}

function lab_period_id_for_number(string $number): int
{
    global $wpdb;

    $tables = lab_membership_tables();
    $periodId = $wpdb->get_var($wpdb->prepare(
        "SELECT period.id
         FROM {$tables['period']} period
         INNER JOIN {$tables['membership']} membership ON membership.id = period.membership_id
         WHERE membership.membership_number = %s
         ORDER BY period.id DESC
         LIMIT 1",
        $number
    ));

    return is_numeric($periodId) ? (int) $periodId : 0;
}
