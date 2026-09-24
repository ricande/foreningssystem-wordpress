<?php

use Foreningssystem\Infrastructure\WordPress\PrivateStorageWarning;
use Foreningssystem\Infrastructure\WordPress\PrivateUploadDirectory;
use Foreningssystem\Infrastructure\WordPress\PublicDocumentsBlock;

$previous = get_option(PrivateUploadDirectory::ACK_OPTION, null);
delete_option(PrivateUploadDirectory::ACK_OPTION);

$restore = static function () use ($previous): void {
    if ($previous === null || $previous === false) {
        delete_option(PrivateUploadDirectory::ACK_OPTION);

        return;
    }

    update_option(PrivateUploadDirectory::ACK_OPTION, $previous, false);
};

$fail = static function (string $message) use ($restore): void {
    $restore();
    \WP_CLI::error($message);
};

wp_set_current_user(1);

if (! PrivateUploadDirectory::isInsideWebRoot() || ! PrivateStorageWarning::needsAttention()) {
    $fail('The lab should detect private files stored under the web root.');
}

$status = PrivateStorageWarning::siteStatus();
$description = (string) ($status['description'] ?? '');

if (
    ($status['status'] ?? '') !== 'recommended'
    || ! str_contains($description, 'Nginx')
    || str_contains($description, 'Nginx skyddas av .htaccess')
    || ! str_contains($description, 'assoc-private')
) {
    $fail('The site health check did not describe the web-root fallback.');
}

ob_start();
PrivateStorageWarning::render();
$notice = (string) ob_get_clean();
$public = PublicDocumentsBlock::render();
$directory = PrivateUploadDirectory::path();

if (
    ! str_contains($notice, 'Nginx')
    || str_contains($notice, $directory)
    || str_contains($public, 'assoc-private')
    || str_contains($public, $directory)
) {
    $fail('The warning or the public block exposed the storage path or hid the Nginx limitation.');
}

$restore();
\WP_CLI::success('Private files under the web root are reported, and public output hides the storage path.');
