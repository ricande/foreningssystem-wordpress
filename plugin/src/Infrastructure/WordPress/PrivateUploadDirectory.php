<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

final class PrivateUploadDirectory
{
    public static function path(): string
    {
        $uploads = wp_upload_dir();

        if (! empty($uploads['error']) || ! is_string($uploads['basedir']) || $uploads['basedir'] === '') {
            throw new \RuntimeException('The upload directory is not available.');
        }

        $directory = $uploads['basedir'] . '/assoc-private';

        if (! is_dir($directory) && ! wp_mkdir_p($directory)) {
            throw new \RuntimeException('The private file directory could not be created.');
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
}
