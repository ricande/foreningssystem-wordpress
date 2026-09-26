<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Document\DocumentFileStore;
use Foreningssystem\Infrastructure\Files\PrivateStorageLocation;

final class WpDocumentFileStore implements DocumentFileStore
{
    public function put(string $name, string $bytes): void
    {
        $this->assertName($name);

        if (! PrivateUploadDirectory::roots()->write($name, $bytes)) {
            throw new \RuntimeException('The document could not be saved.');
        }
    }

    public function read(string $name): string
    {
        $this->assertName($name);
        $path = PrivateUploadDirectory::roots()->locate($name);
        $bytes = $path === null ? false : file_get_contents($path);

        if (! is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('The document was not found.');
        }

        return $bytes;
    }

    public function discard(string $name): void
    {
        $this->assertName($name);
        PrivateUploadDirectory::roots()->delete($name);
    }

    private function assertName(string $name): void
    {
        if (! PrivateStorageLocation::isDocumentName($name)) {
            throw new \RuntimeException('The document file name is not valid.');
        }
    }
}
