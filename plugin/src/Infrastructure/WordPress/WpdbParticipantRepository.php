<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\Participant;
use Foreningssystem\Domain\Meeting\ParticipantRepository;
use Foreningssystem\Domain\Meeting\Presence;

final class WpdbParticipantRepository implements ParticipantRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_meeting_participant';
    }

    public function add(Participant $participant): Participant
    {
        global $wpdb;

        $inserted = $wpdb->insert($this->table(), [
            'meeting_id' => $participant->meetingId(),
            'person_id' => $participant->personId(),
            'presence' => $participant->presence()->value,
            'meeting_duty' => $participant->duty()->value,
        ], ['%d', '%d', '%s', '%s']);

        if ($inserted === false) {
            throw new \RuntimeException('The participant could not be saved.');
        }

        return $participant->withId((int) $wpdb->insert_id);
    }

    public function remove(int $id): void
    {
        global $wpdb;

        $deleted = $wpdb->delete($this->table(), ['id' => $id], ['%d']);

        if ($deleted === false) {
            throw new \RuntimeException('The participant could not be removed.');
        }
    }

    public function find(int $id): ?Participant
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

    public function forPerson(int $personId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE person_id = %d ORDER BY id',
            $personId
        ), ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_map($this->map(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Participant
    {
        return new Participant(
            (int) $row['id'],
            (int) $row['meeting_id'],
            (int) $row['person_id'],
            Presence::from((string) $row['presence']),
            MeetingDuty::from((string) $row['meeting_duty'])
        );
    }
}
