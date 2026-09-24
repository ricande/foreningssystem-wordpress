<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Meeting\MeetingNote;
use Foreningssystem\Domain\Meeting\MeetingNoteRepository;

final class WpdbMeetingNoteRepository implements MeetingNoteRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_meeting_note';
    }

    public function add(MeetingNote $note): MeetingNote
    {
        global $wpdb;

        $data = [
            'meeting_id' => $note->meetingId(),
            'body' => $note->body(),
            'include_in_minutes' => $note->includeInMinutes() ? 1 : 0,
        ];
        $format = ['%d', '%s', '%d'];

        if ($note->agendaItemId() !== null) {
            $data['agenda_item_id'] = $note->agendaItemId();
            $format[] = '%d';
        }

        $inserted = $wpdb->insert($this->table(), $data, $format);

        if ($inserted === false) {
            throw new \RuntimeException('The note could not be saved.');
        }

        return $note->withId((int) $wpdb->insert_id);
    }

    public function save(MeetingNote $note): void
    {
        global $wpdb;

        $id = $note->id();

        if ($id === null) {
            throw new \RuntimeException('Note was not saved.');
        }

        $updated = $wpdb->update($this->table(), [
            'body' => $note->body(),
            'include_in_minutes' => $note->includeInMinutes() ? 1 : 0,
        ], ['id' => $id], ['%s', '%d'], ['%d']);

        if ($updated === false) {
            throw new \RuntimeException('The note could not be saved.');
        }
    }

    public function remove(int $id): void
    {
        global $wpdb;

        if ($wpdb->delete($this->table(), ['id' => $id], ['%d']) === false) {
            throw new \RuntimeException('The note could not be removed.');
        }
    }

    public function find(int $id): ?MeetingNote
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function forMeeting(int $meetingId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE meeting_id = %d ORDER BY id',
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
    private function map(array $row): MeetingNote
    {
        $agendaItemId = $row['agenda_item_id'];

        return new MeetingNote(
            (int) $row['id'],
            (int) $row['meeting_id'],
            is_numeric($agendaItemId) && (int) $agendaItemId > 0 ? (int) $agendaItemId : null,
            (string) $row['body'],
            (int) $row['include_in_minutes'] === 1
        );
    }
}
