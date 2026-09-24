<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Meeting\MinutesPdfStore;

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
        $this->write($revisionId, $name, $bytes);
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . $this->table() . ' SET pdf_storage_name = %s, pdf_source_hash = %s WHERE id = %d',
            $name,
            $hash,
            $revisionId
        ));

        if ($updated === false) {
            throw new \RuntimeException('The PDF reference could not be saved.');
        }
    }

    public function read(int $revisionId): string
    {
        $stored = $this->stored($revisionId);

        if ($stored === null) {
            throw new \RuntimeException('The PDF file was not found.');
        }

        $this->assertName($stored['name'], $revisionId);
        $path = $this->directory() . '/' . $stored['name'];
        $bytes = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('The PDF file was not found.');
        }

        return $bytes;
    }

    private function write(int $revisionId, string $name, string $bytes): void
    {
        $this->assertName($name, $revisionId);
        $directory = $this->directory();
        $path = $directory . '/' . $name;

        if (file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException('The PDF file could not be saved.');
        }
    }

    private function assertName(string $name, int $revisionId): void
    {
        if (! preg_match('/^revision-' . $revisionId . '-[a-f0-9]{64}\.pdf$/', $name)) {
            throw new \RuntimeException('The PDF file name is not valid.');
        }
    }

    private function assertHash(string $hash): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new \RuntimeException('The PDF source hash is not valid.');
        }
    }

    private function directory(): string
    {
        $uploads = wp_upload_dir();

        if (! empty($uploads['error']) || ! is_string($uploads['basedir']) || $uploads['basedir'] === '') {
            throw new \RuntimeException('The upload directory is not available.');
        }

        $directory = $uploads['basedir'] . '/assoc-private';

        if (! is_dir($directory) && ! wp_mkdir_p($directory)) {
            throw new \RuntimeException('The private PDF directory could not be created.');
        }

        $deny = $directory . '/.htaccess';

        if (! is_file($deny)) {
            file_put_contents($deny, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }

        $index = $directory . '/index.php';

        if (! is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        return $directory;
    }

    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_minutes_revision';
    }
}
