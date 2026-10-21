<?php
/**
 * A second database connection changes a minutes revision between the moment a request
 * checks the revision and the moment it writes it. A finalized revision must never be
 * overwritten that way, and a revision must never be locked with text other than the text
 * that is stored. See ADR-0036.
 */

use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;

global $wpdb;

$meetings = $wpdb->prefix . 'assoc_meeting';
$minutes = $wpdb->prefix . 'assoc_minutes';
$revisions = $wpdb->prefix . 'assoc_minutes_revision';
$agenda = $wpdb->prefix . 'assoc_agenda_item';

$cleanup = static function () use ($wpdb, $meetings, $minutes, $revisions, $agenda): void {
    $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-RACE-MINUTES%'));

    foreach (is_array($ids) ? $ids : [] as $id) {
        $wpdb->delete($revisions, ['meeting_id' => (int) $id], ['%d']);
        $wpdb->delete($minutes, ['meeting_id' => (int) $id], ['%d']);
        $wpdb->delete($agenda, ['meeting_id' => (int) $id], ['%d']);
        $wpdb->delete($meetings, ['id' => (int) $id], ['%d']);
    }
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();
$secretary = get_user_by('login', 'lab-secretary');
$chair = get_user_by('login', 'lab-chair');

if (! $secretary instanceof WP_User || ! $chair instanceof WP_User) {
    \WP_CLI::error('Lab users are missing.');
}

$other = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$other->query('SET SESSION innodb_lock_wait_timeout = 1');

/**
 * Runs $write on the second connection just before the $nth query that matches $pattern.
 * Returns the outcome holder; ['done'] tells whether the second write was committed.
 */
$interleave = static function (string $pattern, int $nth, string $write) use ($other): \ArrayObject {
    $outcome = new \ArrayObject(['seen' => 0, 'fired' => false, 'done' => false]);

    add_filter('query', $hook = static function (string $query) use ($pattern, $nth, $write, $other, $outcome, &$hook): string {
        if (preg_match($pattern, $query) !== 1 || ++$outcome['seen'] !== $nth) {
            return $query;
        }

        remove_filter('query', $hook);
        $outcome['fired'] = true;

        try {
            $outcome['done'] = $other->query($write) === true && $other->affected_rows === 1;
        } catch (\mysqli_sql_exception) {
            // A lock wait timeout: the request holds the row, which is the point.
            $outcome['done'] = false;
        }

        return $query;
    });

    return $outcome;
};

$prepare = static function (string $title) use ($secretary): int {
    clean_user_cache($secretary->ID);
    wp_set_current_user($secretary->ID);
    $service = WordpressMeetings::service();
    $typeId = null;

    foreach ($service->types() as $type) {
        if ($type->slug() === 'board_meeting') {
            $typeId = $type->id();
        }
    }

    $meetingId = $service->schedule((int) $typeId, $title, MeetingMoment::fromLocal('2024-03-03 18:00'), '');
    $service->markHeld($meetingId);
    $drafts = WordpressMeetings::minutes();
    $revisionId = $drafts->create($meetingId);
    $drafts->replaceBody($revisionId, 'Texten som granskades.');
    $drafts->submit($revisionId);

    return $revisionId;
};

$row = static function (int $revisionId) use ($wpdb, $revisions): array {
    $found = $wpdb->get_row($wpdb->prepare("SELECT state, body FROM {$revisions} WHERE id = %d", $revisionId), ARRAY_A);

    return is_array($found) ? $found : [];
};

$finalized = RevisionState::Finalized->value;
$adjusting = RevisionState::UnderAdjustment->value;

// 1. The chair locks the revision while the secretary's save is between its check and its
//    write. The save must not turn the locked revision back into an editable one.
$revisionId = $prepare('LAB-RACE-MINUTES omarkering');
$outcome = $interleave(
    '/^UPDATE\s+' . preg_quote($revisions, '/') . '\s+SET\s+body/i',
    1,
    "UPDATE {$revisions} SET state = '{$finalized}' WHERE id = {$revisionId} AND state = '{$adjusting}'"
);
$saveFailed = false;

try {
    WordpressMeetings::minutes()->replaceBody($revisionId, 'En sen ändring.');
} catch (\Throwable) {
    $saveFailed = true;
}

$after = $row($revisionId);

if (! $outcome['fired']) {
    $fail('The second connection never ran during the save.');
}

if ($outcome['done'] && ($after['state'] ?? '') !== $finalized) {
    $fail('A save that checked the revision before it was locked turned the locked revision back into an editable one.');
}

if ($outcome['done'] && ($after['body'] ?? '') !== 'Texten som granskades.') {
    $fail('A locked revision got the text of a save that started before the lock.');
}

if (! $outcome['done'] && ($saveFailed || ($after['body'] ?? '') !== 'En sen ändring.')) {
    $fail('The save was lost although nothing locked the revision.');
}

// 2. The secretary saves new text after the chair's request has read the revision but
//    before it locks it. The revision must not be locked with the text the chair's request
//    read, which is no longer the stored text.
$revisionId = $prepare('LAB-RACE-MINUTES inaktuell');
clean_user_cache($chair->ID);
wp_set_current_user($chair->ID);
$outcome = $interleave(
    '/^SELECT \* FROM ' . preg_quote($revisions, '/') . ' WHERE id = ' . $revisionId . '\b/i',
    2,
    "UPDATE {$revisions} SET body = 'Sekreterarens sista ändring.', hand_edited = 1 WHERE id = {$revisionId} AND state = '{$adjusting}'"
);
$finalizeFailed = false;

try {
    WordpressMeetings::minutes()->finalize($revisionId);
} catch (\Throwable) {
    $finalizeFailed = true;
}

$after = $row($revisionId);

if (! $outcome['fired'] || ! $outcome['done']) {
    $fail('The second connection did not save its text before the revision was locked.');
}

if (($after['state'] ?? '') === $finalized && ($after['body'] ?? '') !== 'Sekreterarens sista ändring.') {
    $fail('The revision was locked with text that was no longer stored, and the last save was lost.');
}

if (! $finalizeFailed && ($after['state'] ?? '') !== $finalized) {
    $fail('The lock reported success without locking the revision.');
}

$other->close();
$cleanup();
\WP_CLI::success('A minutes revision is checked and written under one row lock, and is locked only with the stored text.');
