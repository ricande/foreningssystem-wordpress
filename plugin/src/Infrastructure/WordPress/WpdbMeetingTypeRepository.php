<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Meeting\MeetingType;
use Foreningssystem\Domain\Meeting\MeetingTypeRepository;

final class WpdbMeetingTypeRepository implements MeetingTypeRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_meeting_type';
    }

    public function add(MeetingType $type): MeetingType
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table(),
            [
                'slug' => $type->slug(),
                'name' => $type->name(),
                'sort_order' => $type->sortOrder(),
            ],
            ['%s', '%s', '%d']
        );

        if ($inserted === false) {
            throw new \RuntimeException('The meeting type could not be saved.');
        }

        $id = (int) $wpdb->insert_id;

        if ($id < 1) {
            throw new \RuntimeException('The meeting type could not be saved.');
        }

        return $type->withId($id);
    }

    public function save(MeetingType $type): void
    {
        global $wpdb;

        $id = $type->id();

        if ($id === null || $id < 1) {
            throw new \RuntimeException('The meeting type could not be saved.');
        }

        $updated = $wpdb->update(
            $this->table(),
            [
                'name' => $type->name(),
                'sort_order' => $type->sortOrder(),
            ],
            ['id' => $id],
            ['%s', '%d'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('The meeting type could not be saved.');
        }
    }

    public function find(int $id): ?MeetingType
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function findBySlug(string $slug): ?MeetingType
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
    private function map(array $row): MeetingType
    {
        return new MeetingType(
            (int) $row['id'],
            (string) $row['slug'],
            (string) $row['name'],
            (int) $row['sort_order']
        );
    }
}
