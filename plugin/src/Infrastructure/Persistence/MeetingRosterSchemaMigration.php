<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

final class MeetingRosterSchemaMigration implements Migration
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $charsetCollate,
    ) {
    }

    public function version(): int
    {
        return 5;
    }

    public function statements(): string
    {
        $participant = $this->prefix . 'assoc_meeting_participant';
        $agenda = $this->prefix . 'assoc_agenda_item';

        return "CREATE TABLE {$participant} (
  id bigint(20) unsigned NOT NULL auto_increment,
  meeting_id bigint(20) unsigned NOT NULL default 0,
  person_id bigint(20) unsigned NOT NULL default 0,
  presence varchar(20) NOT NULL default 'present',
  meeting_duty varchar(20) NOT NULL default 'none',
  PRIMARY KEY  (id),
  UNIQUE KEY meeting_person (meeting_id,person_id),
  KEY meeting_id (meeting_id)
) {$this->charsetCollate};
CREATE TABLE {$agenda} (
  id bigint(20) unsigned NOT NULL auto_increment,
  meeting_id bigint(20) unsigned NOT NULL default 0,
  position int(11) NOT NULL default 0,
  title varchar(190) NOT NULL default '',
  number_override varchar(20) NOT NULL default '',
  PRIMARY KEY  (id),
  KEY meeting_position (meeting_id,position)
) {$this->charsetCollate};";
    }

    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($this->statements());
    }
}
