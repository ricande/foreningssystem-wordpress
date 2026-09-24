<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

final class MeetingSchemaMigration implements Migration
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $charsetCollate,
    ) {
    }

    public function version(): int
    {
        return 4;
    }

    public function statements(): string
    {
        $type = $this->prefix . 'assoc_meeting_type';
        $meeting = $this->prefix . 'assoc_meeting';

        return "CREATE TABLE {$type} (
  id bigint(20) unsigned NOT NULL auto_increment,
  slug varchar(50) NOT NULL default '',
  name varchar(100) NOT NULL default '',
  sort_order int(11) NOT NULL default 0,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) {$this->charsetCollate};
CREATE TABLE {$meeting} (
  id bigint(20) unsigned NOT NULL auto_increment,
  type_id bigint(20) unsigned NOT NULL default 0,
  title varchar(190) NOT NULL default '',
  starts_at datetime NOT NULL default '1970-01-01 00:00:00',
  place varchar(190) NOT NULL default '',
  status varchar(20) NOT NULL default 'planned',
  PRIMARY KEY  (id),
  KEY type_id (type_id),
  KEY starts_at (starts_at),
  KEY status (status)
) {$this->charsetCollate};";
    }

    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($this->statements());
        $this->seedSuggestedTypes();
    }

    /**
     * Categories only. No annual-meeting agenda is hardcoded.
     *
     * @return list<array{slug: string, name: string, sort_order: int}>
     */
    public function suggestedTypes(): array
    {
        return [
            ['slug' => 'board_meeting', 'name' => 'Styrelsemöte', 'sort_order' => 10],
            ['slug' => 'annual_meeting', 'name' => 'Årsmöte', 'sort_order' => 20],
            ['slug' => 'extraordinary_annual_meeting', 'name' => 'Extra årsmöte', 'sort_order' => 30],
            ['slug' => 'member_meeting', 'name' => 'Medlemsmöte', 'sort_order' => 40],
            ['slug' => 'working_meeting', 'name' => 'Arbetsmöte', 'sort_order' => 50],
        ];
    }

    private function seedSuggestedTypes(): void
    {
        global $wpdb;

        $table = $this->prefix . 'assoc_meeting_type';

        foreach ($this->suggestedTypes() as $type) {
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE slug = %s", $type['slug']));

            if ($existing) {
                continue;
            }

            $inserted = $wpdb->insert($table, $type, ['%s', '%s', '%d']);

            if ($inserted === false) {
                throw new \RuntimeException('A suggested meeting type could not be saved.');
            }
        }
    }
}
