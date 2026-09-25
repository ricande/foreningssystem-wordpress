<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Board\BoardRoleRepository;

final class WpdbBoardRoleRepository implements BoardRoleRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_board_role';
    }

    public function add(BoardRole $role): BoardRole
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table(),
            [
                'slug' => $role->slug(),
                'name' => $role->name(),
                'allows_multiple' => $role->allowsMultiple() ? 1 : 0,
                'sort_order' => $role->sortOrder(),
            ],
            ['%s', '%s', '%d', '%d']
        );

        if ($inserted === false) {
            throw new \RuntimeException('The board role could not be saved.');
        }

        $id = (int) $wpdb->insert_id;

        if ($id < 1) {
            throw new \RuntimeException('The board role could not be saved.');
        }

        return $role->withId($id);
    }

    public function save(BoardRole $role): void
    {
        global $wpdb;

        $id = $role->id();

        if ($id === null || $id < 1) {
            throw new \RuntimeException('The board role could not be saved.');
        }

        $updated = $wpdb->update(
            $this->table(),
            [
                'name' => $role->name(),
                'allows_multiple' => $role->allowsMultiple() ? 1 : 0,
                'sort_order' => $role->sortOrder(),
            ],
            ['id' => $id],
            ['%s', '%d', '%d'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('The board role could not be saved.');
        }
    }

    public function find(int $id): ?BoardRole
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function findBySlug(string $slug): ?BoardRole
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE slug = %s', $slug), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SELECT * FROM ' . $this->table() . ' ORDER BY sort_order, id', ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_map($this->map(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): BoardRole
    {
        return new BoardRole(
            (int) $row['id'],
            (string) $row['slug'],
            (string) $row['name'],
            (int) $row['allows_multiple'] === 1,
            (int) $row['sort_order']
        );
    }
}
