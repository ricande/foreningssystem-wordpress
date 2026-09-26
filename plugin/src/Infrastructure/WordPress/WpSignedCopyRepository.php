<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Meeting\SignedCopy;
use Foreningssystem\Domain\Meeting\SignedCopyRepository;

final class WpSignedCopyRepository implements SignedCopyRepository
{
    public function add(int $revisionId, string $mediaType, string $storageName): SignedCopy
    {
        global $wpdb;

        $inserted = $wpdb->insert($this->table(), [
            'revision_id' => $revisionId,
            'storage_name' => $storageName,
            'media_type' => $mediaType,
        ], ['%d', '%s', '%s']);

        if ($inserted === false) {
            throw new \RuntimeException('The signed copy could not be saved.');
        }

        return new SignedCopy((int) $wpdb->insert_id, $revisionId, $mediaType, $storageName, null);
    }

    public function replaceCurrent(int $revisionId, int $replacedBy): int
    {
        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . $this->table() . ' SET replaced_by = %d WHERE revision_id = %d AND replaced_by IS NULL AND id <> %d',
            $replacedBy,
            $revisionId,
            $replacedBy
        ));

        if ($updated === false) {
            throw new \RuntimeException('The signed copy could not be replaced.');
        }

        return (int) $updated;
    }

    public function currentForRevision(int $revisionId): ?SignedCopy
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE revision_id = %d AND replaced_by IS NULL ORDER BY id DESC LIMIT 1',
            $revisionId
        ), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function hasStorageName(int $revisionId, string $storageName): bool
    {
        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE revision_id = %d AND storage_name = %s',
            $revisionId,
            $storageName
        ));

        return is_numeric($found) && (int) $found > 0;
    }

    public function find(int $id): ?SignedCopy
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): SignedCopy
    {
        $replacedBy = $row['replaced_by'];

        return new SignedCopy(
            (int) $row['id'],
            (int) $row['revision_id'],
            (string) $row['media_type'],
            (string) $row['storage_name'],
            is_numeric($replacedBy) && (int) $replacedBy > 0 ? (int) $replacedBy : null
        );
    }

    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_signed_copy';
    }
}
