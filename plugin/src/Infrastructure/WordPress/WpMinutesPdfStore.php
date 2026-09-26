<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Meeting\MinutesPdfStore;
use Foreningssystem\Infrastructure\Files\PrivateStorageLocation;

final class WpMinutesPdfStore implements MinutesPdfStore
{
    public function stored(int $revisionId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT pdf_storage_name, pdf_source_hash FROM ' . $this->table() . ' WHERE id = %d',
            $revisionId
        ), ARRAY_A);

        if (! is_array($row) || ! is_string($row['pdf_storage_name']) || $row['pdf_storage_name'] === '' || ! is_string($row['pdf_source_hash']) || $row['pdf_source_hash'] === '') {
            return null;
        }

        return [
            'hash' => $row['pdf_source_hash'],
            'name' => $row['pdf_storage_name'],
        ];
    }

    public function put(int $revisionId, string $hash, string $bytes): void
    {
        global $wpdb;

        $this->assertHash($hash);
        $name = 'revision-' . $revisionId . '-' . $hash . '.pdf';
        // The file the row points at before this write. It stays untouched until the row
        // points at the new file, so a regeneration that fails leaves a working PDF.
        $replaced = $this->stored($revisionId);
        $this->write($revisionId, $name, $bytes);
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . $this->table() . ' SET pdf_storage_name = %s, pdf_source_hash = %s WHERE id = %d',
            $name,
            $hash,
            $revisionId
        ));

        if ($updated === false) {
            // The row still points where it did, so the file just written is not the one
            // being served and can go. Anything else stays.
            if ($replaced === null || $replaced['name'] !== $name) {
                PrivateUploadDirectory::roots()->delete($name);
            }

            throw new \RuntimeException('The PDF reference could not be saved.');
        }

        $stored = $this->stored($revisionId);

        if ($stored === null || $stored['name'] !== $name) {
            // The row cannot be confirmed. Deleting either file could remove the one that
            // is being served, so both stay and the caller hears about it.
            throw new \RuntimeException('The PDF reference could not be confirmed.');
        }

        if (
            $replaced !== null
            && $replaced['name'] !== $name
            && PrivateStorageLocation::isRevisionPdfName($replaced['name'], $revisionId)
        ) {
            PrivateUploadDirectory::roots()->delete($replaced['name']);
        }
    }

    public function read(int $revisionId): string
    {
        $stored = $this->stored($revisionId);

        if ($stored === null) {
            throw new \RuntimeException('The PDF file was not found.');
        }

        $this->assertName($stored['name'], $revisionId);
        $path = PrivateUploadDirectory::roots()->locate($stored['name']);
        $bytes = $path === null ? false : file_get_contents($path);

        if (! is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('The PDF file was not found.');
        }

        return $bytes;
    }

    private function write(int $revisionId, string $name, string $bytes): void
    {
        $this->assertName($name, $revisionId);

        if (! PrivateUploadDirectory::roots()->write($name, $bytes)) {
            throw new \RuntimeException('The PDF file could not be saved.');
        }
    }

    private function assertName(string $name, int $revisionId): void
    {
        if (! PrivateStorageLocation::isRevisionPdfName($name, $revisionId)) {
            throw new \RuntimeException('The PDF file name is not valid.');
        }
    }

    private function assertHash(string $hash): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new \RuntimeException('The PDF source hash is not valid.');
        }
    }

    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_minutes_revision';
    }
}
