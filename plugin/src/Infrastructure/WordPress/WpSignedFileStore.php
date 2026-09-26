<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Meeting\SignedFileStore;
use Foreningssystem\Infrastructure\Files\PrivateStorageLocation;

final class WpSignedFileStore implements SignedFileStore
{
    public function put(string $name, string $bytes): void
    {
        $this->assertName($name);

        if (! PrivateUploadDirectory::roots()->write($name, $bytes)) {
            throw new \RuntimeException('The signed copy could not be saved.');
        }
    }

    public function read(string $name): string
    {
        $this->assertName($name);
        $path = PrivateUploadDirectory::roots()->locate($name);
        $bytes = $path === null ? false : file_get_contents($path);

        if (! is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('The signed copy was not found.');
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
        if (! PrivateStorageLocation::isSignedName($name)) {
            throw new \RuntimeException('The signed copy file name is not valid.');
        }
    }
}
