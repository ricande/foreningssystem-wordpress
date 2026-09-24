<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardAssignmentRepository;
use Foreningssystem\Domain\Membership\AssociationDate;

final class WpdbBoardAssignmentRepository implements BoardAssignmentRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_board_assignment';
    }

    public function add(BoardAssignment $assignment): BoardAssignment
    {
        global $wpdb;

        $data = [
            'person_id' => $assignment->personId(),
            'role_id' => $assignment->roleId(),
            'started_on' => $assignment->startedOn()->iso(),
            'public_contact' => $assignment->publicContact(),
            'term_label' => $assignment->termLabel(),
        ];
        $format = ['%d', '%d', '%s', '%s', '%s'];

        if ($assignment->endedOn() !== null) {
            $data['ended_on'] = $assignment->endedOn()->iso();
            $format[] = '%s';
        }

        $inserted = $wpdb->insert($this->table(), $data, $format);

        if ($inserted === false) {
            throw new \RuntimeException('The assignment could not be saved.');
        }

        return $assignment->withId((int) $wpdb->insert_id);
    }

    public function save(BoardAssignment $assignment): void
    {
        global $wpdb;

        $id = $assignment->id();

        if ($id === null) {
            throw new \RuntimeException('Assignment was not saved.');
        }

        $updated = $wpdb->update(
            $this->table(),
            [
                'person_id' => $assignment->personId(),
                'role_id' => $assignment->roleId(),
                'started_on' => $assignment->startedOn()->iso(),
                'ended_on' => $assignment->endedOn()?->iso(),
                'public_contact' => $assignment->publicContact(),
                'term_label' => $assignment->termLabel(),
            ],
            ['id' => $id],
            ['%d', '%d', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('The assignment could not be saved.');
        }
    }

    public function remove(int $id): void
    {
        global $wpdb;

        $deleted = $wpdb->delete($this->table(), ['id' => $id], ['%d']);

        if ($deleted === false || $deleted < 1) {
            throw new \RuntimeException('The assignment could not be removed.');
        }
    }

    public function find(int $id): ?BoardAssignment
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SELECT * FROM ' . $this->table() . ' ORDER BY started_on, id', ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_map($this->map(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): BoardAssignment
    {
        $endedOn = $row['ended_on'];

        return new BoardAssignment(
            (int) $row['id'],
            (int) $row['person_id'],
            (int) $row['role_id'],
            AssociationDate::fromIso((string) $row['started_on']),
            is_string($endedOn) && $endedOn !== '' && $endedOn !== '0000-00-00'
                ? AssociationDate::fromIso($endedOn)
                : null,
            (string) $row['public_contact'],
            (string) $row['term_label']
        );
    }
}
