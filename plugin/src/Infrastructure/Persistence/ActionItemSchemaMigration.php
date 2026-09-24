<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

final class ActionItemSchemaMigration implements Migration
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $charsetCollate,
    ) {
    }

    public function version(): int
    {
        return 7;
    }

    public function statements(): string
    {
        $table = $this->prefix . 'assoc_action_item';

        return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL auto_increment,
  meeting_id bigint(20) unsigned NOT NULL default 0,
  agenda_item_id bigint(20) unsigned NULL default NULL,
  task varchar(4000) NOT NULL default '',
  assignee_person_id bigint(20) unsigned NULL default NULL,
  due_on date NULL default NULL,
  status varchar(20) NOT NULL default 'open',
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
