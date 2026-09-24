<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Document\DocumentVisibility;
use Foreningssystem\Infrastructure\WordPress\PrivateUploadDirectory;
use Foreningssystem\Infrastructure\WordPress\PublicDocumentsBlock;
use Foreningssystem\Infrastructure\WordPress\WordpressDocuments;

global $wpdb;

$documents = $wpdb->prefix . 'assoc_document';
$pdf = "%PDF-1.4\n1 0 obj\nendobj\n%%EOF";
$boardPdf = "%PDF-1.4\n2 0 obj\nendobj\n%%EOF";

$cleanup = static function () use ($wpdb, $documents): void {
    $rows = $wpdb->get_results($wpdb->prepare("SELECT storage_name FROM {$documents} WHERE title LIKE %s", 'LAB-DOC%'), ARRAY_A);
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

    $wpdb->query($wpdb->prepare("DELETE FROM {$documents} WHERE title LIKE %s", 'LAB-DOC%'));
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
$publicId = $archive->add('LAB-DOC stadgar <2024>', $pdf, DocumentVisibility::Public);
$boardId = $archive->add('LAB-DOC internt', $boardPdf, DocumentVisibility::Board);

clean_user_cache($boardMember->ID);
wp_set_current_user($boardMember->ID);
$deniedUpload = false;

try {
    $archive->add('LAB-DOC otillåten', $pdf, DocumentVisibility::Public);
} catch (NotAllowed) {
    $deniedUpload = true;
}

if (! $deniedUpload || $archive->read($boardId) !== $boardPdf) {
    $fail('A board member could upload, or could not read an internal document.');
}

wp_set_current_user(0);
$publicBytes = $archive->read($publicId);
$deniedRead = false;

try {
    $archive->read($boardId);
} catch (NotAllowed) {
    $deniedRead = true;
}

$html = PublicDocumentsBlock::render();

if (
    ! $deniedRead
    || $publicBytes !== $pdf
    || ! str_contains($html, 'LAB-DOC stadgar &lt;2024&gt;')
    || str_contains($html, 'LAB-DOC stadgar <2024>')
    || str_contains($html, 'LAB-DOC internt')
    || str_contains($html, 'assoc-private')
    || str_contains($html, 'document-')
    || ! str_contains($html, 'assoc_document=' . $publicId)
) {
    $fail('The public archive showed an internal document or a file path.');
}

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$archive->setVisibility($publicId, DocumentVisibility::Board);

if ($archive->read($publicId) !== $pdf) {
    $fail('Changing visibility replaced the file.');
}

wp_set_current_user(0);
$hidden = PublicDocumentsBlock::render();

if (str_contains($hidden, 'LAB-DOC stadgar')) {
    $fail('Making a document internal left it on the public archive.');
}

$cleanup();
\WP_CLI::success('The public archive lists public documents and keeps the file behind a check.');
