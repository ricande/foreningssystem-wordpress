<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Meeting\MeetingTemplateItem;
use Foreningssystem\Domain\Meeting\MeetingTemplateItemRepository;

final class WpdbMeetingTemplateItemRepository implements MeetingTemplateItemRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_meeting_template_item';
    }

    public function add(MeetingTemplateItem $item): MeetingTemplateItem
    {
        global $wpdb;

        $inserted = $wpdb->insert($this->table(), [
            'template_id' => $item->templateId(),
            'position' => $item->position(),
            'title' => $item->title(),
        ], ['%d', '%d', '%s']);

        if ($inserted === false) {
            throw new \RuntimeException('The template heading could not be saved.');
        }

        return $item->withId((int) $wpdb->insert_id);
    }

    public function save(MeetingTemplateItem $item): void
    {
        global $wpdb;

        $id = $item->id();

        if ($id === null) {
            throw new \RuntimeException('Meeting template item was not found.');
        }

        $updated = $wpdb->update(
            $this->table(),
            [
                'position' => $item->position(),
                'title' => $item->title(),
            ],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('The template heading could not be saved.');
        }
    }

    public function remove(int $id): void
    {
        global $wpdb;

        $deleted = $wpdb->delete($this->table(), ['id' => $id], ['%d']);

        if ($deleted === false) {
            throw new \RuntimeException('The template heading could not be removed.');
        }
    }

    public function find(int $id): ?MeetingTemplateItem
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id),
            ARRAY_A
        );

        return is_array($row)
            ? new MeetingTemplateItem(
                (int) $row['id'],
                (int) $row['template_id'],
                (int) $row['position'],
                (string) $row['title']
            )
            : null;
    }

    public function forTemplate(int $templateId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE template_id = %d ORDER BY position ASC, id ASC',
            $templateId
        ), ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        $items = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = new MeetingTemplateItem(
                    (int) $row['id'],
                    (int) $row['template_id'],
                    (int) $row['position'],
                    (string) $row['title']
                );
            }
        }

        return $items;
    }
}
