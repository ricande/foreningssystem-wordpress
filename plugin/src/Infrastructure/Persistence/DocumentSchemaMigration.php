<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

final class DocumentSchemaMigration implements Migration
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $charsetCollate,
    ) {
    }

    public function version(): int
    {
        return 12;
    }

    public function statements(): string
    {
        $documents = $this->prefix . 'assoc_document';

        return "CREATE TABLE {$documents} (
  id bigint(20) unsigned NOT NULL auto_increment,
  title varchar(190) NOT NULL default '',
  visibility varchar(32) NOT NULL default 'board',
  media_type varchar(80) NOT NULL default '',
  storage_name varchar(190) NOT NULL default '',
  PRIMARY KEY  (id),
  KEY visibility (visibility)
) {$this->charsetCollate};";
    }

    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($this->statements());
    }
}
