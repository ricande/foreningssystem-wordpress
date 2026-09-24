<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

final class SignedCopySchemaMigration implements Migration
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $charsetCollate,
    ) {
    }

    public function version(): int
    {
        return 10;
    }

    public function statements(): string
    {
        $copy = $this->prefix . 'assoc_signed_copy';
        $audit = $this->prefix . 'assoc_audit_event';

        return "CREATE TABLE {$copy} (
  id bigint(20) unsigned NOT NULL auto_increment,
  revision_id bigint(20) unsigned NOT NULL default 0,
  storage_name varchar(190) NOT NULL default '',
  media_type varchar(80) NOT NULL default '',
  replaced_by bigint(20) unsigned NULL default NULL,
  PRIMARY KEY  (id),
  KEY revision_id (revision_id)
) {$this->charsetCollate};
CREATE TABLE {$audit} (
  id bigint(20) unsigned NOT NULL auto_increment,
  object_type varchar(40) NOT NULL default '',
  object_id bigint(20) unsigned NOT NULL default 0,
  action varchar(40) NOT NULL default '',
  actor_user_id bigint(20) unsigned NULL default NULL,
  created_at datetime NULL default NULL,
  PRIMARY KEY  (id),
  KEY object_lookup (object_type, object_id)
) {$this->charsetCollate};";
    }

    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($this->statements());
    }
}
