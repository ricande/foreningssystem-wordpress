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
        $path = PrivateUploadDirectory::path() . '/' . $name;

        if (file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException('The signed copy could not be saved.');
        }
    }

    public function read(string $name): string
    {
        $this->assertName($name);
        $path = PrivateUploadDirectory::path() . '/' . $name;
        $bytes = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('The signed copy was not found.');
        }

        return $bytes;
    }

    private function assertName(string $name): void
    {
        if (! PrivateStorageLocation::isSignedName($name)) {
            throw new \RuntimeException('The signed copy file name is not valid.');
        }
    }
}
