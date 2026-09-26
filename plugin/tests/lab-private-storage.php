<?php

use Foreningssystem\Infrastructure\Files\PrivateStorageLocation;
use Foreningssystem\Infrastructure\WordPress\PrivateStorageWarning;
use Foreningssystem\Infrastructure\WordPress\PrivateUploadDirectory;
use Foreningssystem\Infrastructure\WordPress\PublicDocumentsBlock;
use Foreningssystem\Infrastructure\WordPress\WpDocumentFileStore;

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

// Moving the storage root must not make a stored file invisible: fallback → A → B → back.
$documentBytes = "%PDF-1.4\nLAB-STORAGE\n%%EOF";
$documentName = 'document-' . hash('sha256', $documentBytes) . '.pdf';
$documents = new WpDocumentFileStore();
$fallbackRoot = PrivateUploadDirectory::path();
$first = PrivateStorageLocation::canonical(get_temp_dir() . 'lab-private-a');
$second = PrivateStorageLocation::canonical(get_temp_dir() . 'lab-private-b');
$configured = $first;
$route = static function () use (&$configured): string {
    return $configured;
};

$cleanUp = static function () use ($documents, $documentName, $route, $first, $second): void {
    remove_filter('foreningsplugin_private_directory', $route);

    try {
        $documents->discard($documentName);
    } catch (\Throwable) {
    }

    foreach ([$first, $second] as $directory) {
        foreach (['.htaccess', 'index.php', $documentName] as $name) {
            if (is_file($directory . '/' . $name)) {
                unlink($directory . '/' . $name);
            }
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    delete_option(PrivateUploadDirectory::ROOT_OPTION);
    delete_option(PrivateUploadDirectory::EARLIER_ROOTS_OPTION);
};

$storageFail = static function (string $message) use ($cleanUp, $fail): void {
    $cleanUp();
    $fail($message);
};

$documents->put($documentName, $documentBytes);

if (! is_file($fallbackRoot . '/' . $documentName)) {
    $storageFail('The document was not written to the fallback directory.');
}

add_filter('foreningsplugin_private_directory', $route);

foreach ([$first, $second] as $moved) {
    $configured = $moved;
    $active = PrivateUploadDirectory::path();

    if (
        $active !== $moved
        || ! is_file($moved . '/' . $documentName)
        || is_file($fallbackRoot . '/' . $documentName)
        || $documents->read($documentName) !== $documentBytes
        || get_option(PrivateUploadDirectory::ROOT_OPTION) !== $moved
        || get_option(PrivateUploadDirectory::EARLIER_ROOTS_OPTION, []) !== []
    ) {
        $storageFail('The private document did not follow the storage root to ' . $moved . '.');
    }
}

if (is_file($first . '/' . $documentName)) {
    $storageFail('The first custom directory kept a copy of the document.');
}

remove_filter('foreningsplugin_private_directory', $route);
$backToFallback = PrivateUploadDirectory::path();

if (
    $backToFallback !== PrivateStorageLocation::canonical($fallbackRoot)
    || ! is_file($fallbackRoot . '/' . $documentName)
    || $documents->read($documentName) !== $documentBytes
    || get_option(PrivateUploadDirectory::ROOT_OPTION) !== $backToFallback
) {
    $storageFail('The document did not follow the storage root back to the fallback directory.');
}

$cleanUp();

if (is_file($fallbackRoot . '/' . $documentName)) {
    $fail('The lab document was left in the private directory.');
}

$restore();
\WP_CLI::success('Private files under the web root are reported, the storage path stays hidden, and documents follow the storage root.');
