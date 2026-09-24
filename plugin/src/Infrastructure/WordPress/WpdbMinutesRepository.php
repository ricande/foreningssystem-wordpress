<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Meeting\MinutesRepository;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\PublicationVisibility;
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

        $data = [
            'minutes_id' => $revision->minutesId(),
            'meeting_id' => $revision->meetingId(),
            'revision_number' => $revision->number(),
            'state' => $revision->state()->value,
            'body' => $revision->body(),
            'payload' => $revision->payload(),
            'hand_edited' => $revision->handEdited() ? 1 : 0,
            'visibility' => $revision->visibility()->value,
        ];
        $format = ['%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s'];

        if ($revision->correctsRevisionId() !== null) {
            $data['corrects_revision_id'] = $revision->correctsRevisionId();
            $format[] = '%d';
        }

        $inserted = $wpdb->insert($this->revisions(), $data, $format);

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

        $table = $this->revisions();
        $corrects = $revision->correctsRevisionId();
        $superseded = $revision->supersededBy();
        $sql = "UPDATE {$table} SET body = %s, payload = %s, hand_edited = %d, state = %s, visibility = %s, corrects_revision_id = "
            . ($corrects === null ? 'NULL' : '%d')
            . ', superseded_by = '
            . ($superseded === null ? 'NULL' : '%d')
            . ' WHERE id = %d';
        $args = [$revision->body(), $revision->payload(), $revision->handEdited() ? 1 : 0, $revision->state()->value, $revision->visibility()->value];

        if ($corrects !== null) {
            $args[] = $corrects;
        }

        if ($superseded !== null) {
            $args[] = $superseded;
        }

        $args[] = $id;
        $updated = $wpdb->query($wpdb->prepare($sql, ...$args));

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

    public function openForMeeting(int $meetingId): ?MinutesRevision
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->revisions() . ' WHERE meeting_id = %d AND state IN (%s, %s) ORDER BY revision_number DESC LIMIT 1',
            $meetingId,
            RevisionState::Draft->value,
            RevisionState::UnderAdjustment->value
        ), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function latestForMeeting(int $meetingId): ?MinutesRevision
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->revisions() . ' WHERE meeting_id = %d ORDER BY revision_number DESC LIMIT 1',
            $meetingId
        ), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function forMeeting(int $meetingId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->revisions() . ' WHERE meeting_id = %d ORDER BY revision_number, id',
            $meetingId
        ), ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        $revisions = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $revisions[] = $this->map($row);
            }
        }

        return $revisions;
    }

    public function publicRevisions(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->revisions() . ' WHERE state = %s AND visibility = %s AND superseded_by IS NULL',
            RevisionState::Finalized->value,
            PublicationVisibility::Public->value
        ), ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        $revisions = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $revisions[] = $this->map($row);
            }
        }

        return $revisions;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): MinutesRevision
    {
        $visibility = PublicationVisibility::tryFrom((string) ($row['visibility'] ?? PublicationVisibility::Board->value))
            ?? PublicationVisibility::Board;

        return new MinutesRevision(
            (int) $row['id'],
            (int) $row['minutes_id'],
            (int) $row['meeting_id'],
            (int) $row['revision_number'],
            RevisionState::from((string) $row['state']),
            (string) $row['body'],
            (string) $row['payload'],
            (int) $row['hand_edited'] === 1,
            is_numeric($row['corrects_revision_id']) && (int) $row['corrects_revision_id'] > 0 ? (int) $row['corrects_revision_id'] : null,
            is_numeric($row['superseded_by']) && (int) $row['superseded_by'] > 0 ? (int) $row['superseded_by'] : null,
            $visibility
        );
    }
}
