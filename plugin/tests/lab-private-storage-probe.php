<?php
/**
 * The warning about private files under the web root follows what the web server really
 * does with a test file, not only the administrator's acknowledgement. See ADR-0037.
 *
 * WP-CLI runs in its own container, where the site address does not reach the web server,
 * so the probe address is pointed at the WordPress service for this test.
 */

use Foreningssystem\Infrastructure\Files\PrivateStorageExposure;
use Foreningssystem\Infrastructure\WordPress\PrivateStorageProbe;
use Foreningssystem\Infrastructure\WordPress\PrivateStorageWarning;
use Foreningssystem\Infrastructure\WordPress\PrivateUploadDirectory;

$directory = PrivateUploadDirectory::path();
$guard = $directory . '/.htaccess';
$guardBytes = (string) file_get_contents($guard);
$previousAck = get_option(PrivateUploadDirectory::ACK_OPTION, null);
$route = static function (string $url): string {
    return (string) preg_replace('#^https?://[^/]+#', 'http://wordpress', $url);
};

$restore = static function () use ($guard, $guardBytes, $previousAck, $route): void {
    file_put_contents($guard, $guardBytes);
    remove_filter('foreningsplugin_private_storage_probe_url', $route);
    delete_transient(PrivateStorageProbe::TRANSIENT);

    if ($previousAck === null || $previousAck === false) {
        delete_option(PrivateUploadDirectory::ACK_OPTION);
    } else {
        update_option(PrivateUploadDirectory::ACK_OPTION, $previousAck, false);
    }
};

$fail = static function (string $message) use ($restore): void {
    $restore();
    \WP_CLI::error($message);
};

$leftovers = static function () use ($directory): array {
    return glob($directory . '/probe-*') ?: [];
};

wp_set_current_user(1);
add_filter('foreningsplugin_private_storage_probe_url', $route);
delete_option(PrivateUploadDirectory::ACK_OPTION);

// Apache honours the .htaccess the plugin writes: the server refuses the file.
if (PrivateStorageProbe::result(true) !== PrivateStorageExposure::BLOCKED || PrivateStorageWarning::needsAttention()) {
    $fail('A directory the web server refuses was not recognized as blocked.');
}

// Nginx, or Apache with AllowOverride None: the guard file does nothing.
file_put_contents($guard, "# The lab ignores this file.\n");
update_option(PrivateUploadDirectory::ACK_OPTION, '1', false);

if (PrivateStorageProbe::result(true) !== PrivateStorageExposure::EXPOSED) {
    $fail('A directory the web server serves was not recognized as exposed.');
}

if (! PrivateStorageWarning::needsAttention() || ! PrivateStorageWarning::isExposed()) {
    $fail('The acknowledgement hid a directory the web server serves.');
}

$status = PrivateStorageWarning::siteStatus();
ob_start();
PrivateStorageWarning::render();
$notice = (string) ob_get_clean();

if (
    ($status['status'] ?? '') !== 'critical'
    || ! str_contains($notice, 'notice-error')
    || ! str_contains($notice, 'assoc_recheck_private_storage')
    || str_contains($notice, 'assoc_ack_private_storage')
    || str_contains($notice, $directory)
) {
    $fail('An exposed directory did not give a critical status and an error notice without an acknowledgement button.');
}

if ($leftovers() !== []) {
    $fail('The probe left its test file in the private directory.');
}

$restore();

if (PrivateStorageProbe::result(true) === PrivateStorageExposure::EXPOSED) {
    $fail('The restored guard did not block the directory again.');
}

delete_transient(PrivateStorageProbe::TRANSIENT);
\WP_CLI::success('The private directory warning follows what the web server does with a test file.');
