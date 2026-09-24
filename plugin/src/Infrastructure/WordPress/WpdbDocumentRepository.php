<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Document\AssociationDocument;
use Foreningssystem\Domain\Document\DocumentRepository;
use Foreningssystem\Domain\Document\DocumentVisibility;

final class WpdbDocumentRepository implements DocumentRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_document';
    }

    public function add(AssociationDocument $document): AssociationDocument
    {
        global $wpdb;

        $inserted = $wpdb->insert($this->table(), [
            'title' => $document->title(),
            'visibility' => $document->visibility()->value,
            'media_type' => $document->mediaType(),
            'storage_name' => $document->storageName(),
        ], ['%s', '%s', '%s', '%s']);

        if ($inserted === false) {
            throw new \RuntimeException('The document could not be saved.');
        }

        return $document->withId((int) $wpdb->insert_id);
    }

    public function save(AssociationDocument $document): void
    {
        global $wpdb;

        $id = $document->id();

        if ($id === null) {
            throw new \RuntimeException('The document was not saved.');
        }

        $updated = $wpdb->update(
            $this->table(),
            ['visibility' => $document->visibility()->value],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('The document could not be saved.');
        }
    }

    public function find(int $id): ?AssociationDocument
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

        $documents = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $documents[] = $this->map($row);
            }
        }

        return $documents;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): AssociationDocument
    {
        $visibility = DocumentVisibility::tryFrom((string) $row['visibility']) ?? DocumentVisibility::Board;

        return new AssociationDocument(
            (int) $row['id'],
            (string) $row['title'],
            $visibility,
            (string) $row['media_type'],
            (string) $row['storage_name']
        );
    }
}
