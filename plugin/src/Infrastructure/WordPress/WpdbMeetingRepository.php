<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRepository;
use Foreningssystem\Domain\Meeting\MeetingStatus;

final class WpdbMeetingRepository implements MeetingRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_meeting';
    }

    public function add(Meeting $meeting): Meeting
    {
        global $wpdb;

        $inserted = $wpdb->insert($this->table(), [
            'type_id' => $meeting->typeId(),
            'title' => $meeting->title(),
            'starts_at' => $meeting->startsAt()->local(),
            'place' => $meeting->place(),
            'status' => $meeting->status()->value,
        ], ['%d', '%s', '%s', '%s', '%s']);

        if ($inserted === false) {
            throw new \RuntimeException('The meeting could not be saved.');
        }

        return $meeting->withId((int) $wpdb->insert_id);
    }

    public function save(Meeting $meeting): void
    {
        global $wpdb;

        $id = $meeting->id();

        if ($id === null) {
            throw new \RuntimeException('Meeting was not saved.');
        }

        $updated = $wpdb->update($this->table(), [
            'type_id' => $meeting->typeId(),
            'title' => $meeting->title(),
            'starts_at' => $meeting->startsAt()->local(),
            'place' => $meeting->place(),
            'status' => $meeting->status()->value,
        ], ['id' => $id], ['%d', '%s', '%s', '%s', '%s'], ['%d']);

        if ($updated === false) {
            throw new \RuntimeException('The meeting could not be saved.');
        }
    }

    public function find(int $id): ?Meeting
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SELECT * FROM ' . $this->table() . ' ORDER BY starts_at DESC, id DESC', ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_map($this->map(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Meeting
    {
        return new Meeting(
            (int) $row['id'],
            (int) $row['type_id'],
            (string) $row['title'],
            MeetingMoment::fromLocal((string) $row['starts_at']),
            (string) $row['place'],
            MeetingStatus::from((string) $row['status'])
        );
    }
}
