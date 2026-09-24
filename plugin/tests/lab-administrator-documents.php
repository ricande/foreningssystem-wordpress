<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Document\DocumentVisibility;
use Foreningssystem\Infrastructure\WordPress\MemberDocumentsBlock;
use Foreningssystem\Infrastructure\WordPress\PrivateUploadDirectory;
use Foreningssystem\Infrastructure\WordPress\PublicDocumentsBlock;
use Foreningssystem\Infrastructure\WordPress\WordpressDocuments;

global $wpdb;

$documents = $wpdb->prefix . 'assoc_document';
$pdf = "%PDF-1.4\n1 0 obj\nendobj\n%%EOF";
$boardPdf = "%PDF-1.4\n2 0 obj\nendobj\n%%EOF";

$cleanup = static function () use ($wpdb, $documents): void {
    $rows = $wpdb->get_results($wpdb->prepare("SELECT storage_name FROM {$documents} WHERE title LIKE %s", 'LAB-ADMIN%'), ARRAY_A);
    $directory = PrivateUploadDirectory::path();

    if (is_array($rows)) {
        foreach ($rows as $row) {
            $name = is_array($row) ? (string) ($row['storage_name'] ?? '') : '';

            if (preg_match('/^document-[a-f0-9]{64}\.(pdf|jpg|png)$/', $name) === 1) {
                $path = $directory . '/' . $name;

                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    $wpdb->query($wpdb->prepare("DELETE FROM {$documents} WHERE title LIKE %s", 'LAB-ADMIN%'));
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();
$secretary = get_user_by('login', 'lab-secretary');
$boardMember = get_user_by('login', 'lab-board-member');

if (! $secretary instanceof WP_User || ! $boardMember instanceof WP_User) {
    \WP_CLI::error('Lab users are missing.');
}

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$archive = WordpressDocuments::archive();
$adminId = $archive->add('LAB-ADMIN avtal', $pdf, DocumentVisibility::Administrator);
$boardId = $archive->add('LAB-ADMIN internt', $boardPdf, DocumentVisibility::Board);
$managerTitles = array_map(static fn ($document): string => $document->title(), $archive->officerList());
clean_user_cache($boardMember->ID);
wp_set_current_user($boardMember->ID);
$boardTitles = array_map(static fn ($document): string => $document->title(), $archive->officerList());
$boardDenied = false;
$denial = '';

try {
    $archive->read($adminId);
} catch (NotAllowed $error) {
    $boardDenied = true;
    $denial = $error->getMessage();
}

$boardBytes = $archive->read($boardId);
wp_set_current_user(0);
$publicHtml = PublicDocumentsBlock::render();
$memberHtml = MemberDocumentsBlock::render();
$GLOBALS['lab_admin_download_status'] = 0;
$GLOBALS['lab_admin_download_message'] = '';

function lab_administrator_document_die(string|\WP_Error $message, string $title = '', array $args = []): void
{
    unset($title);
    $GLOBALS['lab_admin_download_status'] = (int) ($args['response'] ?? 0);
    $GLOBALS['lab_admin_download_message'] = is_string($message) ? $message : $message->get_error_message();

    throw new \RuntimeException('download-stopped');
}

$dieHandler = static fn (): string => 'lab_administrator_document_die';
add_filter('wp_die_handler', $dieHandler);
ob_start();

try {
    $_GET['assoc_document'] = (string) $adminId;
    \Foreningssystem\Infrastructure\WordPress\DocumentDownload::maybeSend();
} catch (\RuntimeException $error) {
    if ($error->getMessage() !== 'download-stopped') {
        ob_end_clean();
        remove_filter('wp_die_handler', $dieHandler);
        throw $error;
    }
}

$sent = (string) ob_get_clean();
remove_filter('wp_die_handler', $dieHandler);
wp_set_current_user($secretary->ID);
clean_user_cache($secretary->ID);
$adminBytes = $archive->read($adminId);

if (
    $adminBytes !== $pdf
    || $boardBytes !== $boardPdf
    || ! $boardDenied
    || $denial !== Capabilities::MANAGE_DOCUMENTS
    || ! in_array('LAB-ADMIN avtal', $managerTitles, true)
    || in_array('LAB-ADMIN avtal', $boardTitles, true)
    || ! in_array('LAB-ADMIN internt', $boardTitles, true)
    || str_contains($publicHtml, 'LAB-ADMIN')
    || str_contains($memberHtml, 'LAB-ADMIN')
    || str_contains($publicHtml, 'assoc-private')
    || str_contains($publicHtml, 'document-')
    || (int) $GLOBALS['lab_admin_download_status'] !== 403
    || str_contains((string) $GLOBALS['lab_admin_download_message'] . $sent, '%PDF')
    || str_contains((string) $GLOBALS['lab_admin_download_message'] . $sent, 'assoc-private')
) {
    $fail('An administrator document was readable without permission to manage documents.');
}

$cleanup();
\WP_CLI::success('An administrator document is readable only by someone who manages documents.');
