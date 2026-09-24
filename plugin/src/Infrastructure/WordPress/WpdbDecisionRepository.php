<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Meeting\Decision;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\DecisionRepository;
use Foreningssystem\Domain\Membership\AssociationDate;

final class WpdbDecisionRepository implements DecisionRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_decision';
    }

    public function add(Decision $decision): Decision
    {
        global $wpdb;

        $data = [
            'meeting_id' => $decision->meetingId(),
            'wording' => $decision->wording(),
            'follow_up' => $decision->followUp()->value,
        ];
        $format = ['%d', '%s', '%s'];

        if ($decision->agendaItemId() !== null) {
            $data['agenda_item_id'] = $decision->agendaItemId();
            $format[] = '%d';
        }

        if ($decision->responsiblePersonId() !== null) {
            $data['responsible_person_id'] = $decision->responsiblePersonId();
            $format[] = '%d';
        }

        if ($decision->deadline() !== null) {
            $data['deadline'] = $decision->deadline()->iso();
            $format[] = '%s';
        }

        $inserted = $wpdb->insert($this->table(), $data, $format);

        if ($inserted === false) {
            throw new \RuntimeException('The decision could not be saved.');
        }

        return $decision->withId((int) $wpdb->insert_id);
    }

    public function save(Decision $decision): void
    {
        global $wpdb;

        $id = $decision->id();

        if ($id === null) {
            throw new \RuntimeException('Decision was not saved.');
        }

        $table = $this->table();
        $responsible = $decision->responsiblePersonId();
        $deadline = $decision->deadline()?->iso();
        $sql = "UPDATE {$table} SET wording = %s, follow_up = %s, responsible_person_id = "
            . ($responsible === null ? 'NULL' : '%d')
            . ', deadline = '
            . ($deadline === null ? 'NULL' : '%s')
            . ' WHERE id = %d';
        $args = [$decision->wording(), $decision->followUp()->value];

        if ($responsible !== null) {
            $args[] = $responsible;
        }

        if ($deadline !== null) {
            $args[] = $deadline;
        }

        $args[] = $id;
        $updated = $wpdb->query($wpdb->prepare($sql, ...$args));

        if ($updated === false) {
            throw new \RuntimeException('The decision could not be saved.');
        }
    }

    public function remove(int $id): void
    {
        global $wpdb;

        if ($wpdb->delete($this->table(), ['id' => $id], ['%d']) === false) {
            throw new \RuntimeException('The decision could not be removed.');
        }
    }

    public function find(int $id): ?Decision
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SELECT * FROM ' . $this->table() . ' ORDER BY id', ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_map($this->map(...), $rows);
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
    private function map(array $row): Decision
    {
        $agendaItemId = $row['agenda_item_id'];
        $responsibleId = $row['responsible_person_id'];
        $deadline = $row['deadline'];

        return new Decision(
            (int) $row['id'],
            (int) $row['meeting_id'],
            is_numeric($agendaItemId) && (int) $agendaItemId > 0 ? (int) $agendaItemId : null,
            (string) $row['wording'],
            is_numeric($responsibleId) && (int) $responsibleId > 0 ? (int) $responsibleId : null,
            is_string($deadline) && $deadline !== '' && $deadline !== '0000-00-00' ? AssociationDate::fromIso($deadline) : null,
            DecisionFollowUp::from((string) $row['follow_up'])
        );
    }
}
