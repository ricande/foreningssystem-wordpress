<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

final class BoardSchemaMigration implements Migration
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $charsetCollate,
    ) {
    }

    public function version(): int
    {
        return 3;
    }

    public function statements(): string
    {
        $role = $this->prefix . 'assoc_board_role';
        $assignment = $this->prefix . 'assoc_board_assignment';

        return "CREATE TABLE {$role} (
  id bigint(20) unsigned NOT NULL auto_increment,
  slug varchar(50) NOT NULL default '',
  name varchar(100) NOT NULL default '',
  allows_multiple tinyint(1) NOT NULL default 0,
  sort_order int(11) NOT NULL default 0,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) {$this->charsetCollate};
CREATE TABLE {$assignment} (
  id bigint(20) unsigned NOT NULL auto_increment,
  person_id bigint(20) unsigned NOT NULL default 0,
  role_id bigint(20) unsigned NOT NULL default 0,
  started_on date NOT NULL default '1970-01-01',
  ended_on date NULL default NULL,
  public_contact varchar(190) NOT NULL default '',
  term_label varchar(100) NOT NULL default '',
  PRIMARY KEY  (id),
  KEY person_id (person_id),
  KEY role_id (role_id)
) {$this->charsetCollate};";
    }

    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($this->statements());
        $this->seedSuggestedRoles();
    }

    /**
     * Suggested offices. An association can add its own later. Auditor and election
     * committee are board roles and follow the same membership rule.
     *
     * @return list<array{slug: string, name: string, allows_multiple: int, sort_order: int}>
     */
    public function suggestedRoles(): array
    {
        return [
            ['slug' => 'chair', 'name' => 'Ordförande', 'allows_multiple' => 0, 'sort_order' => 10],
            ['slug' => 'treasurer', 'name' => 'Kassör', 'allows_multiple' => 0, 'sort_order' => 20],
            ['slug' => 'secretary', 'name' => 'Sekreterare', 'allows_multiple' => 0, 'sort_order' => 30],
            ['slug' => 'alternate', 'name' => 'Suppleant', 'allows_multiple' => 1, 'sort_order' => 40],
            ['slug' => 'auditor', 'name' => 'Revisor', 'allows_multiple' => 1, 'sort_order' => 50],
            ['slug' => 'election_committee', 'name' => 'Valberedning', 'allows_multiple' => 1, 'sort_order' => 60],
        ];
    }

    private function seedSuggestedRoles(): void
    {
        global $wpdb;

        $table = $this->prefix . 'assoc_board_role';

        foreach ($this->suggestedRoles() as $role) {
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE slug = %s", $role['slug']));

            if ($existing) {
                continue;
            }

            $inserted = $wpdb->insert($table, $role, ['%s', '%s', '%d', '%d']);

            if ($inserted === false) {
                throw new \RuntimeException('A suggested board role could not be saved.');
            }
        }
    }
}
