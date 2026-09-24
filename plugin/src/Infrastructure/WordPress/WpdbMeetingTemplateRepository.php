<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Meeting\MeetingTemplate;
use Foreningssystem\Domain\Meeting\MeetingTemplateRepository;

final class WpdbMeetingTemplateRepository implements MeetingTemplateRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_meeting_template';
    }

    public function add(MeetingTemplate $template): MeetingTemplate
    {
        global $wpdb;

        $inserted = $wpdb->insert($this->table(), [
            'type_id' => $template->typeId(),
            'name' => $template->name(),
        ], ['%d', '%s']);

        if ($inserted === false) {
            throw new \RuntimeException('The meeting template could not be saved.');
        }

        return $template->withId((int) $wpdb->insert_id);
    }

    public function remove(int $id): void
    {
        global $wpdb;

        $deleted = $wpdb->delete($this->table(), ['id' => $id], ['%d']);

        if ($deleted === false) {
            throw new \RuntimeException('The meeting template could not be removed.');
        }
    }

    public function find(int $id): ?MeetingTemplate
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SELECT * FROM ' . $this->table() . ' ORDER BY id ASC', ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        $templates = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $templates[] = $this->map($row);
            }
        }

        return $templates;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): MeetingTemplate
    {
        return new MeetingTemplate((int) $row['id'], (int) $row['type_id'], (string) $row['name']);
    }
}
