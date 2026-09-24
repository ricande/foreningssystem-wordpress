<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

final class MinutesPublicationSchemaMigration implements Migration
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $charsetCollate,
    ) {
    }

    public function version(): int
    {
        return 11;
    }

    public function statements(): string
    {
        $revision = $this->prefix . 'assoc_minutes_revision';

        return "CREATE TABLE {$revision} (
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
  pdf_storage_name varchar(190) NULL default NULL,
  pdf_source_hash varchar(64) NULL default NULL,
  visibility varchar(32) NOT NULL default 'board',
  PRIMARY KEY  (id),
  KEY meeting_id (meeting_id),
  KEY state (state),
  KEY visibility (visibility)
) {$this->charsetCollate};";
    }

    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($this->statements());
    }
}
