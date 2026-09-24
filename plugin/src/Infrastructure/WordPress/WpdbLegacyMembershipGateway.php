<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Infrastructure\Persistence\LegacyMembershipGateway;
use Foreningssystem\Infrastructure\Persistence\MigrationException;

final class WpdbLegacyMembershipGateway implements LegacyMembershipGateway
{
    private ?bool $participantHasStart = null;

    public function __construct(private readonly string $prefix)
    {
    }

    public function legacyRows(): array
    {
        global $wpdb;

        $legacy = $this->prefix . 'assoc_membership_legacy';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy));

        if ($exists !== $legacy) {
            return [];
        }

        $rows = $wpdb->get_results('SELECT * FROM ' . $legacy . ' ORDER BY id', ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    public function findMembershipId(string $number): ?int
    {
        global $wpdb;

        $id = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . $this->prefix . 'assoc_membership WHERE membership_number = %s',
            $number
        ));

        return is_numeric($id) ? (int) $id : null;
    }

    public function insertMembership(string $number, string $kind): int
    {
        global $wpdb;

        $inserted = $wpdb->insert($this->prefix . 'assoc_membership', [
            'membership_number' => $number,
            'kind' => $kind,
        ], ['%s', '%s']);

        if ($inserted === false) {
            throw new MigrationException('A membership could not be copied from the previous schema.');
        }

        return (int) $wpdb->insert_id;
    }

    public function hasParticipant(int $membershipId, int $personId): bool
    {
        global $wpdb;

        $id = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . $this->prefix . 'assoc_membership_participant WHERE membership_id = %d AND person_id = %d LIMIT 1',
            $membershipId,
            $personId
        ));

        return is_numeric($id);
    }

    public function insertParticipant(int $membershipId, int $personId, string $startedOn): void
    {
        global $wpdb;

        $data = [
            'membership_id' => $membershipId,
            'person_id' => $personId,
            'role' => 'member',
            'is_primary' => 1,
        ];
        $format = ['%d', '%d', '%s', '%d'];

        if ($this->participantHasStart()) {
            $data['started_on'] = $startedOn;
            $format[] = '%s';
        }

        $inserted = $wpdb->insert($this->prefix . 'assoc_membership_participant', $data, $format);

        if ($inserted === false) {
            throw new MigrationException('A membership participant could not be copied from the previous schema.');
        }
    }

    public function hasPeriod(int $membershipId, string $status, string $startedOn, ?string $endedOn): bool
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ended_on FROM ' . $this->prefix . 'assoc_membership_period WHERE membership_id = %d AND status = %s AND started_on = %s',
            $membershipId,
            $status,
            $startedOn
        ), ARRAY_A);

        if (! is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $stored = $row['ended_on'] ?? null;
            $normalized = is_string($stored) && $stored !== '' && $stored !== '0000-00-00' ? $stored : null;

            if ($normalized === $endedOn) {
                return true;
            }
        }

        return false;
    }

    public function insertPeriod(int $membershipId, string $status, string $startedOn, ?string $endedOn, string $historicalClass): void
    {
        global $wpdb;

        $data = [
            'membership_id' => $membershipId,
            'status' => $status,
            'started_on' => $startedOn,
            'historical_class' => $historicalClass,
        ];
        $format = ['%d', '%s', '%s', '%s'];

        if ($endedOn !== null) {
            $data['ended_on'] = $endedOn;
            $format[] = '%s';
        }

        $inserted = $wpdb->insert($this->prefix . 'assoc_membership_period', $data, $format);

        if ($inserted === false) {
            throw new MigrationException('A membership period could not be copied from the previous schema.');
        }
    }

    public function transaction(callable $callback): void
    {
        global $wpdb;

        $wpdb->query('START TRANSACTION');

        try {
            $callback();
            $wpdb->query('COMMIT');
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');

            throw $error;
        }
    }

    private function participantHasStart(): bool
    {
        if ($this->participantHasStart !== null) {
            return $this->participantHasStart;
        }

        global $wpdb;

        $columns = $wpdb->get_results('SHOW COLUMNS FROM ' . $this->prefix . "assoc_membership_participant LIKE 'started_on'", ARRAY_A);
        $this->participantHasStart = is_array($columns) && $columns !== [];

        return $this->participantHasStart;
    }
}
