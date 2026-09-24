<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Document\DocumentFileStore;

final class WpDocumentFileStore implements DocumentFileStore
{
    public function put(string $name, string $bytes): void
    {
        $this->assertName($name);
        $path = PrivateUploadDirectory::path() . '/' . $name;

        if (file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException('The document could not be saved.');
        }
    }

    public function read(string $name): string
    {
        $this->assertName($name);
        $path = PrivateUploadDirectory::path() . '/' . $name;
        $bytes = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('The document was not found.');
        }

        return $bytes;
    }

    private function assertName(string $name): void
    {
        if (basename($name) !== $name || ! preg_match('/^document-[a-f0-9]{64}\.(pdf|jpg|png)$/', $name)) {
            throw new \RuntimeException('The document file name is not valid.');
        }
    }
}
