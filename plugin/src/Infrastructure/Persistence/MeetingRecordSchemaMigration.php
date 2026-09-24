<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

final class MeetingRecordSchemaMigration implements Migration
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $charsetCollate,
    ) {
    }

    public function version(): int
    {
        return 6;
    }

    public function statements(): string
    {
        $note = $this->prefix . 'assoc_meeting_note';
        $decision = $this->prefix . 'assoc_decision';

        return "CREATE TABLE {$note} (
  id bigint(20) unsigned NOT NULL auto_increment,
  meeting_id bigint(20) unsigned NOT NULL default 0,
  agenda_item_id bigint(20) unsigned NULL default NULL,
  body varchar(4000) NOT NULL default '',
  include_in_minutes tinyint(1) NOT NULL default 0,
  PRIMARY KEY  (id),
  KEY meeting_id (meeting_id),
  KEY agenda_item_id (agenda_item_id)
) {$this->charsetCollate};
CREATE TABLE {$decision} (
  id bigint(20) unsigned NOT NULL auto_increment,
  meeting_id bigint(20) unsigned NOT NULL default 0,
  agenda_item_id bigint(20) unsigned NULL default NULL,
  wording varchar(4000) NOT NULL default '',
  responsible_person_id bigint(20) unsigned NULL default NULL,
  deadline date NULL default NULL,
  follow_up varchar(20) NOT NULL default 'open',
  PRIMARY KEY  (id),
  KEY meeting_id (meeting_id),
  KEY agenda_item_id (agenda_item_id)
) {$this->charsetCollate};";
    }

    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($this->statements());
    }
}
