<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

final class MembershipSchemaMigration implements Migration
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $charsetCollate,
    ) {
    }

    public function version(): int
    {
        return 2;
    }

    public function statements(): string
    {
        $person = $this->prefix . 'assoc_person';
        $membership = $this->prefix . 'assoc_membership';

        return "CREATE TABLE {$person} (
  id bigint(20) unsigned NOT NULL auto_increment,
  first_name varchar(100) NOT NULL default '',
  last_name varchar(100) NOT NULL default '',
  email varchar(190) NOT NULL default '',
  status varchar(20) NOT NULL default 'known',
  wp_user_id bigint(20) unsigned NULL default NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY wp_user_id (wp_user_id)
) {$this->charsetCollate};
CREATE TABLE {$membership} (
  id bigint(20) unsigned NOT NULL auto_increment,
  person_id bigint(20) unsigned NOT NULL default 0,
  membership_number varchar(50) NOT NULL default '',
  membership_type varchar(50) NOT NULL default '',
  status varchar(20) NOT NULL default '',
  started_on date NOT NULL default '1970-01-01',
  ended_on date NULL default NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY membership_number (membership_number),
  KEY person_id (person_id)
) {$this->charsetCollate};";
    }

    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($this->statements());
    }
}
