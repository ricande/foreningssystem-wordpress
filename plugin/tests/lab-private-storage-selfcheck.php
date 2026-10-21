<?php
/**
 * The check from ADR-0037 runs on its own inside the web server, as it does for an
 * administrator who presses "Check again", with no address filter. In the lab the site
 * address (localhost:<port>) does not reach Apache from inside its container, so the answer
 * must come from 127.0.0.1 with the site's host name (ADR-0039). The Documents page then
 * shows where protected files stand, both when they are safe and when they are not.
 */

use Foreningssystem\Infrastructure\Files\PrivateStorageExposure;
use Foreningssystem\Infrastructure\WordPress\PrivateStorageProbe;
use Foreningssystem\Infrastructure\WordPress\PrivateUploadDirectory;

$directory = PrivateUploadDirectory::path();
$guard = $directory . '/.htaccess';
$guardBytes = (string) file_get_contents($guard);
$sessions = WP_Session_Tokens::get_instance(1);
$expiration = time() + HOUR_IN_SECONDS;
$token = $sessions->create($expiration);
$cookies = [
    AUTH_COOKIE => wp_generate_auth_cookie(1, $expiration, 'auth', $token),
    LOGGED_IN_COOKIE => wp_generate_auth_cookie(1, $expiration, 'logged_in', $token),
];

$restore = static function () use ($guard, $guardBytes, $sessions, $token): void {
    file_put_contents($guard, $guardBytes);
    $sessions->destroy($token);
    delete_transient(PrivateStorageProbe::TRANSIENT);
};

$fail = static function (string $message) use ($restore): void {
    $restore();
    \WP_CLI::error($message);
};

$_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
wp_set_current_user(1);
$nonce = wp_create_nonce('assoc_recheck_private_storage');
$documentsPage = 'http://wordpress/wp-admin/admin.php?page=foreningsplugin-documents';

$recheck = static function () use ($cookies, $nonce, $documentsPage, $fail): void {
    $response = wp_remote_post('http://wordpress/wp-admin/admin-post.php', [
        'timeout' => 30,
        'redirection' => 0,
        'cookies' => $cookies,
        'headers' => ['Referer' => $documentsPage],
        'body' => ['action' => 'assoc_recheck_private_storage', '_wpnonce' => $nonce],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 302) {
        $fail('"Check again" did not run in the web server.');
    }
};

$page = static function () use ($cookies, $documentsPage): string {
    $response = wp_remote_get($documentsPage, ['timeout' => 30, 'cookies' => $cookies]);

    return is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);
};

// The web server wrote the result. This process has cached the option as missing, so it
// is read from the database.
$stored = static function (): array {
    global $wpdb;

    $value = maybe_unserialize($wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        '_transient_' . PrivateStorageProbe::TRANSIENT
    )));

    return is_array($value) ? $value : [];
};

delete_transient(PrivateStorageProbe::TRANSIENT);

// Apache honours the guard: every test file is refused, and the page says the files are safe.
$recheck();
$report = $stored();

if (($report['result'] ?? '') !== PrivateStorageExposure::BLOCKED || ! str_contains((string) ($report['address'] ?? ''), '127.0.0.1')) {
    $fail('The check in the web server did not find the blocked directory through 127.0.0.1: ' . wp_json_encode($report));
}

$html = $page();

if (! preg_match('/<div class="[^"]*assoc-private-storage-status[^"]*">.*?<\/div>/s', $html, $box) || ! str_contains($box[0], 'notice-success') || str_contains($box[0], $directory)) {
    $fail('The Documents page did not say that protected files are safe.');
}

// The guard does nothing (Nginx, AllowOverride None): the page says the files are not safe.
file_put_contents($guard, "# The lab ignores this file.\n");
$recheck();
$report = $stored();
$html = $page();

if (
    ($report['result'] ?? '') !== PrivateStorageExposure::EXPOSED
    || ! preg_match('/<div class="[^"]*assoc-private-storage-status[^"]*">.*?<\/div>/s', $html, $box)
    || ! str_contains($box[0], 'notice-error')
) {
    $fail('The check in the web server did not find the served directory, or the page did not say so.');
}

if (glob($directory . '/probe-*') !== []) {
    $fail('The check left a test file in the private directory.');
}

$restore();
\WP_CLI::success('The web server checks the private directory on its own and the Documents page shows the result either way.');
