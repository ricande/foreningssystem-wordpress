<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

/**
 * Gives each participation a start date and allows a later participation on the same membership.
 * Rows copied from schema 14, and participants saved before this column existed, use the earliest period start.
 * That date is the only start the earlier schema recorded. It is not a guess about a later join.
 */
final class ParticipantIntervalSchemaMigration implements Migration
{
    public function __construct(
        private readonly string $prefix,
        string $charsetCollate,
    ) {
        unset($charsetCollate);
    }

    public function version(): int
    {
        return 16;
    }

    public function up(): void
    {
        global $wpdb;

        (new LegacyMembershipImporter())->copy(new \Foreningssystem\Infrastructure\WordPress\WpdbLegacyMembershipGateway($this->prefix));

        $participant = $this->prefix . 'assoc_membership_participant';
        $period = $this->prefix . 'assoc_membership_period';
        $columns = $wpdb->get_results('SHOW COLUMNS FROM ' . $participant . " LIKE 'started_on'", ARRAY_A);

        if (! is_array($columns) || $columns === []) {
            $added = $wpdb->query('ALTER TABLE ' . $participant . ' ADD COLUMN started_on date NULL DEFAULT NULL AFTER is_primary');

            if ($added === false) {
                throw new MigrationException('Participation start dates could not be added.');
            }
        }

        $filled = $wpdb->query(
            'UPDATE ' . $participant . ' participant
            SET started_on = (
                SELECT MIN(period.started_on) FROM ' . $period . ' period WHERE period.membership_id = participant.membership_id
            )
            WHERE started_on IS NULL'
        );

        if ($filled === false) {
            throw new MigrationException('Participation start dates could not be filled in.');
        }

        $missing = $wpdb->get_var('SELECT COUNT(*) FROM ' . $participant . ' WHERE started_on IS NULL');

        if ((int) $missing > 0) {
            throw new MigrationException('A participation has no period from which to take a start date.');
        }

        $required = $wpdb->query('ALTER TABLE ' . $participant . ' MODIFY started_on date NOT NULL');

        if ($required === false) {
            throw new MigrationException('Participation start dates could not be required.');
        }

        $indexes = $wpdb->get_results('SHOW INDEX FROM ' . $participant . " WHERE Key_name = 'membership_person'", ARRAY_A);
        $unique = false;
        $present = false;

        if (is_array($indexes)) {
            foreach ($indexes as $index) {
                if (! is_array($index)) {
                    continue;
                }

                $present = true;

                if ((int) $index['Non_unique'] === 0) {
                    $unique = true;
                }
            }
        }

        if ($unique) {
            $dropped = $wpdb->query('ALTER TABLE ' . $participant . ' DROP INDEX membership_person');

            if ($dropped === false) {
                throw new MigrationException('Participation history could not be stored.');
            }

            $present = false;
        }

        if (! $present) {
            $added = $wpdb->query('ALTER TABLE ' . $participant . ' ADD INDEX membership_person (membership_id, person_id)');

            if ($added === false) {
                throw new MigrationException('Participation history could not be indexed.');
            }
        }
    }
}
