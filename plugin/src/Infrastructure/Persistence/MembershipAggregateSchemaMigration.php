<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

/**
 * Schema 14 stored one person, one number and one period on assoc_membership.
 * Each such row becomes one membership, one member participant and one period.
 * Distinct numbers are not merged, even when they name the same person.
 */
final class MembershipAggregateSchemaMigration implements Migration
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $charsetCollate,
    ) {
    }

    public function version(): int
    {
        return 15;
    }

    public static function kindFromLegacy(string $type): string
    {
        return match (strtolower(trim($type))) {
            'youth', 'ungdom' => 'youth',
            'family', 'familj' => 'family',
            'company', 'corporate', 'organisation', 'företag', 'foretag' => 'company',
            'ordinary', 'ordinarie' => 'ordinary',
            default => 'ordinary',
        };
    }

    public function statements(): string
    {
        $person = $this->prefix . 'assoc_person';
        $organization = $this->prefix . 'assoc_organization';
        $membership = $this->prefix . 'assoc_membership';
        $period = $this->prefix . 'assoc_membership_period';
        $participant = $this->prefix . 'assoc_membership_participant';
        $relationship = $this->prefix . 'assoc_guardian_relationship';
        $approval = $this->prefix . 'assoc_guardian_approval';
        $identity = $this->prefix . 'assoc_personal_identity';

        return "CREATE TABLE {$person} (
  id bigint(20) unsigned NOT NULL auto_increment,
  first_name varchar(100) NOT NULL default '',
  last_name varchar(100) NOT NULL default '',
  email varchar(190) NOT NULL default '',
  status varchar(20) NOT NULL default 'known',
  wp_user_id bigint(20) unsigned NULL default NULL,
  birth_date date NULL default NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY wp_user_id (wp_user_id)
) {$this->charsetCollate};
CREATE TABLE {$organization} (
  id bigint(20) unsigned NOT NULL auto_increment,
  name varchar(190) NOT NULL default '',
  organization_number varchar(20) NULL default NULL,
  email varchar(190) NOT NULL default '',
  postal_address text NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY organization_number (organization_number)
) {$this->charsetCollate};
CREATE TABLE {$membership} (
  id bigint(20) unsigned NOT NULL auto_increment,
  membership_number varchar(50) NOT NULL default '',
  kind varchar(32) NOT NULL default '',
  organization_id bigint(20) unsigned NULL default NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY membership_number (membership_number),
  KEY organization_id (organization_id)
) {$this->charsetCollate};
CREATE TABLE {$period} (
  id bigint(20) unsigned NOT NULL auto_increment,
  membership_id bigint(20) unsigned NOT NULL default 0,
  status varchar(20) NOT NULL default '',
  started_on date NOT NULL default '1970-01-01',
  ended_on date NULL default NULL,
  historical_class varchar(50) NOT NULL default '',
  PRIMARY KEY  (id),
  KEY membership_id (membership_id)
) {$this->charsetCollate};
CREATE TABLE {$participant} (
  id bigint(20) unsigned NOT NULL auto_increment,
  membership_id bigint(20) unsigned NOT NULL default 0,
  person_id bigint(20) unsigned NOT NULL default 0,
  role varchar(32) NOT NULL default '',
  is_primary tinyint(1) NOT NULL default 0,
  ended_on date NULL default NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY membership_person (membership_id, person_id),
  KEY person_id (person_id)
) {$this->charsetCollate};
CREATE TABLE {$relationship} (
  id bigint(20) unsigned NOT NULL auto_increment,
  child_person_id bigint(20) unsigned NOT NULL default 0,
  guardian_person_id bigint(20) unsigned NOT NULL default 0,
  relationship_label varchar(80) NOT NULL default '',
  started_on date NULL default NULL,
  ended_on date NULL default NULL,
  PRIMARY KEY  (id),
  KEY child_person_id (child_person_id),
  KEY guardian_person_id (guardian_person_id)
) {$this->charsetCollate};
CREATE TABLE {$approval} (
  id bigint(20) unsigned NOT NULL auto_increment,
  child_person_id bigint(20) unsigned NOT NULL default 0,
  guardian_person_id bigint(20) unsigned NOT NULL default 0,
  purpose varchar(190) NOT NULL default '',
  basis_note text NOT NULL,
  approved_at datetime NOT NULL default '1970-01-01 00:00:00',
  method varchar(80) NOT NULL default '',
  notice_version varchar(40) NOT NULL default '',
  recorded_by_user_id bigint(20) unsigned NULL default NULL,
  withdrawn_at datetime NULL default NULL,
  note text NOT NULL,
  PRIMARY KEY  (id),
  KEY child_person_id (child_person_id)
) {$this->charsetCollate};
CREATE TABLE {$identity} (
  id bigint(20) unsigned NOT NULL auto_increment,
  person_id bigint(20) unsigned NOT NULL default 0,
  identifier varchar(13) NOT NULL default '',
  identifier_type varchar(40) NOT NULL default '',
  purpose varchar(190) NOT NULL default '',
  basis_note text NOT NULL,
  collected_on date NOT NULL default '1970-01-01',
  recorded_by_user_id bigint(20) unsigned NULL default NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY person_id (person_id)
) {$this->charsetCollate};";
    }

    public function up(): void
    {
        global $wpdb;

        $membership = $this->prefix . 'assoc_membership';
        $legacy = $this->prefix . 'assoc_membership_legacy';
        $columns = $wpdb->get_results('SHOW COLUMNS FROM ' . $membership . " LIKE 'person_id'", ARRAY_A);

        if (is_array($columns) && $columns !== []) {
            $renamed = $wpdb->query('RENAME TABLE ' . $membership . ' TO ' . $legacy);

            if ($renamed === false) {
                throw new MigrationException('The membership table could not be prepared for the new model.');
            }
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($this->statements());
        $this->copyLegacy($legacy);
    }

    private function copyLegacy(string $legacy): void
    {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy));

        if ($exists !== $legacy) {
            return;
        }

        $rows = $wpdb->get_results('SELECT * FROM ' . $legacy . ' ORDER BY id', ARRAY_A);

        if (! is_array($rows)) {
            return;
        }

        $membership = $this->prefix . 'assoc_membership';
        $period = $this->prefix . 'assoc_membership_period';
        $participant = $this->prefix . 'assoc_membership_participant';

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $number = (string) $row['membership_number'];
            $already = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $membership . ' WHERE membership_number = %s', $number));

            if (is_numeric($already)) {
                continue;
            }

            $type = (string) $row['membership_type'];
            $kind = self::kindFromLegacy($type);
            $inserted = $wpdb->insert($membership, [
                'membership_number' => $number,
                'kind' => $kind === 'company' ? 'ordinary' : $kind,
            ], ['%s', '%s']);

            if ($inserted === false) {
                throw new MigrationException('A membership could not be copied from the previous schema.');
            }

            $membershipId = (int) $wpdb->insert_id;
            $participantInserted = $wpdb->insert($participant, [
                'membership_id' => $membershipId,
                'person_id' => (int) $row['person_id'],
                'role' => 'member',
                'is_primary' => 1,
            ], ['%d', '%d', '%s', '%d']);

            if ($participantInserted === false) {
                throw new MigrationException('A membership participant could not be copied from the previous schema.');
            }

            $periodData = [
                'membership_id' => $membershipId,
                'status' => (string) $row['status'],
                'started_on' => (string) $row['started_on'],
                'historical_class' => $type,
            ];
            $periodFormat = ['%d', '%s', '%s', '%s'];
            $ended = $row['ended_on'] ?? null;

            if (is_string($ended) && $ended !== '' && $ended !== '0000-00-00') {
                $periodData['ended_on'] = $ended;
                $periodFormat[] = '%s';
            }

            $periodInserted = $wpdb->insert($period, $periodData, $periodFormat);

            if ($periodInserted === false) {
                throw new MigrationException('A membership period could not be copied from the previous schema.');
            }
        }
    }
}
