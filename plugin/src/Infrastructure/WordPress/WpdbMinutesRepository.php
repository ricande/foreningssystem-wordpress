<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Meeting\MinutesRepository;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\RevisionState;

final class WpdbMinutesRepository implements MinutesRepository
{
    private function documents(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_minutes';
    }

    private function revisions(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_minutes_revision';
    }

    public function findDocumentId(int $meetingId): ?int
    {
        global $wpdb;

        $id = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $this->documents() . ' WHERE meeting_id = %d', $meetingId));

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    public function addDocument(int $meetingId): int
    {
        global $wpdb;

        $inserted = $wpdb->insert($this->documents(), ['meeting_id' => $meetingId], ['%d']);

        if ($inserted === false) {
            throw new \RuntimeException('The minutes document could not be saved.');
        }

        return (int) $wpdb->insert_id;
    }

    public function nextNumber(int $meetingId): int
    {
        global $wpdb;

        $number = $wpdb->get_var($wpdb->prepare(
            'SELECT MAX(revision_number) FROM ' . $this->revisions() . ' WHERE meeting_id = %d',
            $meetingId
        ));

        return is_numeric($number) ? ((int) $number) + 1 : 1;
    }

    public function addRevision(MinutesRevision $revision): MinutesRevision
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->revisions(),
            [
                'minutes_id' => $revision->minutesId(),
                'meeting_id' => $revision->meetingId(),
                'revision_number' => $revision->number(),
                'state' => $revision->state()->value,
                'body' => $revision->body(),
                'payload' => $revision->payload(),
                'hand_edited' => $revision->handEdited() ? 1 : 0,
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%d']
        );

        if ($inserted === false) {
            throw new \RuntimeException('The minutes draft could not be saved.');
        }

        return $revision->withId((int) $wpdb->insert_id);
    }

    public function saveRevision(MinutesRevision $revision): void
    {
        global $wpdb;

        $id = $revision->id();

        if ($id === null) {
            throw new \RuntimeException('Minutes draft was not saved.');
        }

        $updated = $wpdb->update(
            $this->revisions(),
            [
                'body' => $revision->body(),
                'payload' => $revision->payload(),
                'hand_edited' => $revision->handEdited() ? 1 : 0,
                'state' => $revision->state()->value,
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('The minutes draft could not be saved.');
        }
    }

    public function findRevision(int $id): ?MinutesRevision
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->revisions() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function draftForMeeting(int $meetingId): ?MinutesRevision
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->revisions() . ' WHERE meeting_id = %d AND state = %s ORDER BY revision_number DESC LIMIT 1',
            $meetingId,
            RevisionState::Draft->value
        ), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): MinutesRevision
    {
        return new MinutesRevision(
            (int) $row['id'],
            (int) $row['minutes_id'],
            (int) $row['meeting_id'],
            (int) $row['revision_number'],
            RevisionState::from((string) $row['state']),
            (string) $row['body'],
            (string) $row['payload'],
            (int) $row['hand_edited'] === 1
        );
    }
}
