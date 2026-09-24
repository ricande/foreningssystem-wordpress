<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

final class MeetingTemplateSchemaMigration implements Migration
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $charsetCollate,
    ) {
    }

    public function version(): int
    {
        return 13;
    }

    public function statements(): string
    {
        $template = $this->prefix . 'assoc_meeting_template';
        $item = $this->prefix . 'assoc_meeting_template_item';

        return "CREATE TABLE {$template} (
  id bigint(20) unsigned NOT NULL auto_increment,
  type_id bigint(20) unsigned NOT NULL default 0,
  name varchar(190) NOT NULL default '',
  PRIMARY KEY  (id),
  KEY type_id (type_id)
) {$this->charsetCollate};
CREATE TABLE {$item} (
  id bigint(20) unsigned NOT NULL auto_increment,
  template_id bigint(20) unsigned NOT NULL default 0,
  position int(11) NOT NULL default 0,
  title varchar(190) NOT NULL default '',
  PRIMARY KEY  (id),
  KEY template_id (template_id)
) {$this->charsetCollate};";
    }

    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($this->statements());
    }
}
