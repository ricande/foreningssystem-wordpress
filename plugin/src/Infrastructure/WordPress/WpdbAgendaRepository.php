<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Meeting\AgendaItem;
use Foreningssystem\Domain\Meeting\AgendaRepository;

final class WpdbAgendaRepository implements AgendaRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_agenda_item';
    }

    public function add(AgendaItem $item): AgendaItem
    {
        global $wpdb;

        $inserted = $wpdb->insert($this->table(), [
            'meeting_id' => $item->meetingId(),
            'position' => $item->position(),
            'title' => $item->title(),
            'number_override' => $item->numberOverride(),
        ], ['%d', '%d', '%s', '%s']);

        if ($inserted === false) {
            throw new \RuntimeException('The agenda item could not be saved.');
        }

        return $item->withId((int) $wpdb->insert_id);
    }

    public function save(AgendaItem $item): void
    {
        global $wpdb;

        $id = $item->id();

        if ($id === null) {
            throw new \RuntimeException('Agenda item was not saved.');
        }

        $updated = $wpdb->update($this->table(), [
            'meeting_id' => $item->meetingId(),
            'position' => $item->position(),
            'title' => $item->title(),
            'number_override' => $item->numberOverride(),
        ], ['id' => $id], ['%d', '%d', '%s', '%s'], ['%d']);

        if ($updated === false) {
            throw new \RuntimeException('The agenda item could not be saved.');
        }
    }

    public function remove(int $id): void
    {
        global $wpdb;

        $deleted = $wpdb->delete($this->table(), ['id' => $id], ['%d']);

        if ($deleted === false) {
            throw new \RuntimeException('The agenda item could not be removed.');
        }
    }

    public function find(int $id): ?AgendaItem
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function forMeeting(int $meetingId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE meeting_id = %d ORDER BY position, id',
            $meetingId
        ), ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_map($this->map(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): AgendaItem
    {
        return new AgendaItem(
            (int) $row['id'],
            (int) $row['meeting_id'],
            (int) $row['position'],
            (string) $row['title'],
            (string) $row['number_override']
        );
    }
}
