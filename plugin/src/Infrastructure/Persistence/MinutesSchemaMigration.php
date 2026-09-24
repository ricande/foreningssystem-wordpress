<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

final class MinutesSchemaMigration implements Migration
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $charsetCollate,
    ) {
    }

    public function version(): int
    {
        return 8;
    }

    public function statements(): string
    {
        $minutes = $this->prefix . 'assoc_minutes';
        $revision = $this->prefix . 'assoc_minutes_revision';

        return "CREATE TABLE {$minutes} (
  id bigint(20) unsigned NOT NULL auto_increment,
  meeting_id bigint(20) unsigned NOT NULL default 0,
  PRIMARY KEY  (id),
  UNIQUE KEY meeting_id (meeting_id)
) {$this->charsetCollate};
CREATE TABLE {$revision} (
  id bigint(20) unsigned NOT NULL auto_increment,
  minutes_id bigint(20) unsigned NOT NULL default 0,
  meeting_id bigint(20) unsigned NOT NULL default 0,
  revision_number bigint(20) unsigned NOT NULL default 1,
  state varchar(32) NOT NULL default 'draft',
  body longtext NOT NULL,
  payload longtext NOT NULL,
  hand_edited tinyint(1) NOT NULL default 0,
  corrects_revision_id bigint(20) unsigned NULL default NULL,
  superseded_by bigint(20) unsigned NULL default NULL,
  PRIMARY KEY  (id),
  KEY meeting_id (meeting_id),
  KEY state (state)
) {$this->charsetCollate};";
    }

    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($this->statements());
    }
}
