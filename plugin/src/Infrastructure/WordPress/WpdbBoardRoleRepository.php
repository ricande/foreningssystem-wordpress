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
