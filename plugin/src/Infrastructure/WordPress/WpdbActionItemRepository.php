<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Meeting\ActionItem;
use Foreningssystem\Domain\Meeting\ActionItemRepository;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Membership\AssociationDate;

final class WpdbActionItemRepository implements ActionItemRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_action_item';
    }

    public function add(ActionItem $item): ActionItem
    {
        global $wpdb;

        $data = [
            'meeting_id' => $item->meetingId(),
            'task' => $item->task(),
            'status' => $item->status()->value,
        ];
        $format = ['%d', '%s', '%s'];

        if ($item->agendaItemId() !== null) {
            $data['agenda_item_id'] = $item->agendaItemId();
            $format[] = '%d';
        }

        if ($item->assigneePersonId() !== null) {
            $data['assignee_person_id'] = $item->assigneePersonId();
            $format[] = '%d';
        }

        if ($item->dueOn() !== null) {
            $data['due_on'] = $item->dueOn()->iso();
            $format[] = '%s';
        }

        $inserted = $wpdb->insert($this->table(), $data, $format);

        if ($inserted === false) {
            throw new \RuntimeException('The action item could not be saved.');
        }

        return $item->withId((int) $wpdb->insert_id);
    }

    public function save(ActionItem $item): void
    {
        global $wpdb;

        $id = $item->id();

        if ($id === null) {
            throw new \RuntimeException('Action item was not saved.');
        }

        $table = $this->table();
        $assignee = $item->assigneePersonId();
        $dueOn = $item->dueOn()?->iso();
        $sql = "UPDATE {$table} SET task = %s, status = %s, assignee_person_id = "
            . ($assignee === null ? 'NULL' : '%d')
            . ', due_on = '
            . ($dueOn === null ? 'NULL' : '%s')
            . ' WHERE id = %d';
        $args = [$item->task(), $item->status()->value];

        if ($assignee !== null) {
            $args[] = $assignee;
        }

        if ($dueOn !== null) {
            $args[] = $dueOn;
        }

        $args[] = $id;
        $updated = $wpdb->query($wpdb->prepare($sql, ...$args));

        if ($updated === false) {
            throw new \RuntimeException('The action item could not be saved.');
        }
    }

    public function remove(int $id): void
    {
        global $wpdb;

        if ($wpdb->delete($this->table(), ['id' => $id], ['%d']) === false) {
            throw new \RuntimeException('The action item could not be removed.');
        }
    }

    public function find(int $id): ?ActionItem
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
    private function map(array $row): ActionItem
    {
        $agendaItemId = $row['agenda_item_id'];
        $assigneeId = $row['assignee_person_id'];
        $dueOn = $row['due_on'];

        return new ActionItem(
            (int) $row['id'],
            (int) $row['meeting_id'],
            is_numeric($agendaItemId) && (int) $agendaItemId > 0 ? (int) $agendaItemId : null,
            (string) $row['task'],
            is_numeric($assigneeId) && (int) $assigneeId > 0 ? (int) $assigneeId : null,
            is_string($dueOn) && $dueOn !== '' && $dueOn !== '0000-00-00' ? AssociationDate::fromIso($dueOn) : null,
            ActionStatus::from((string) $row['status'])
        );
    }
}
