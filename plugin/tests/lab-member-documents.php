<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Document\DocumentVisibility;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\MemberDocumentsBlock;
use Foreningssystem\Infrastructure\WordPress\PrivateUploadDirectory;
use Foreningssystem\Infrastructure\WordPress\PublicDocumentsBlock;
use Foreningssystem\Infrastructure\WordPress\WordpressDocuments;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$documents = $wpdb->prefix . 'assoc_document';
$people = $wpdb->prefix . 'assoc_person';
$memberships = $wpdb->prefix . 'assoc_membership';
$pdf = "%PDF-1.4\n1 0 obj\nendobj\n%%EOF";
$publicPdf = "%PDF-1.4\n2 0 obj\nendobj\n%%EOF";

$cleanup = static function () use ($wpdb, $documents, $people, $memberships): void {
    $rows = $wpdb->get_results($wpdb->prepare("SELECT storage_name FROM {$documents} WHERE title LIKE %s", 'LAB-MEMBER%'), ARRAY_A);
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

    $wpdb->query($wpdb->prepare("DELETE FROM {$documents} WHERE title LIKE %s", 'LAB-MEMBER%'));
    $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", 'ada-member-doc@example.test'));

    if (! $personId) {
        $personId = lab_person_id_for_membership_number('LAB-MEMBER-1');
    }

    if ($personId) {
        lab_delete_person_memberships((int) $personId);
        $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
    }

    $user = get_user_by('login', 'lab-member');

    if ($user instanceof WP_User) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user((int) $user->ID);
    }
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

$userId = wp_insert_user([
    'user_login' => 'lab-member',
    'user_pass' => wp_generate_password(24),
    'user_email' => 'lab-member@example.test',
    'role' => 'subscriber',
]);

if (! is_int($userId)) {
    $fail('The member lab user could not be created.');
}

wp_set_current_user(1);
clean_user_cache(1);
$personId = WordpressPeople::service()->register('Ada', 'Member', 'ada-member-doc@example.test', 'LAB-MEMBER-1', 'ordinarie', AssociationDate::fromIso('2024-01-01'));

if ($wpdb->query($wpdb->prepare("UPDATE {$people} SET wp_user_id = %d WHERE id = %d", $userId, $personId)) === false) {
    $fail('The member account could not be linked.');
}

clean_user_cache($secretary->ID);
wp_set_current_user($secretary->ID);
$archive = WordpressDocuments::archive();
$memberDocument = $archive->add('LAB-MEMBER stadgar', $pdf, DocumentVisibility::Member);
$publicDocument = $archive->add('LAB-MEMBER offentlig', $publicPdf, DocumentVisibility::Public);
$secretaryDenied = false;

try {
    $archive->read($memberDocument);
} catch (NotAllowed) {
    $secretaryDenied = true;
}

clean_user_cache($boardMember->ID);
wp_set_current_user($boardMember->ID);
$boardDenied = false;

try {
    $archive->read($memberDocument);
} catch (NotAllowed) {
    $boardDenied = true;
}

wp_set_current_user(0);
$loggedOutDenied = false;

try {
    $archive->read($memberDocument);
} catch (NotAllowed) {
    $loggedOutDenied = true;
}

$publicHtml = PublicDocumentsBlock::render();
$hiddenMemberHtml = MemberDocumentsBlock::render();
clean_user_cache($userId);
wp_set_current_user($userId);
$memberBytes = $archive->read($memberDocument);
$memberHtml = MemberDocumentsBlock::render();
// The block is personalized, so the page it renders on must not be cached for everyone.
$memberDocumentsUncacheable = defined('DONOTCACHEPAGE') && DONOTCACHEPAGE === true;
$membershipId = lab_period_id_for_number('LAB-MEMBER-1');
wp_set_current_user(1);
clean_user_cache(1);
WordpressPeople::service()->endMembership($membershipId, AssociationDate::fromIso('2024-06-01'));
wp_set_current_user($userId);
clean_user_cache($userId);
$endedDenied = false;

try {
    $archive->read($memberDocument);
} catch (NotAllowed) {
    $endedDenied = true;
}

$endedHtml = MemberDocumentsBlock::render();
$GLOBALS['lab_download_status'] = 0;
$GLOBALS['lab_download_message'] = '';

function lab_member_document_die(string|\WP_Error $message, string $title = '', array $args = []): void
{
    unset($title);
    $GLOBALS['lab_download_status'] = (int) ($args['response'] ?? 0);
    $GLOBALS['lab_download_message'] = is_string($message) ? $message : $message->get_error_message();

    throw new \RuntimeException('download-stopped');
}

$dieHandler = static fn (): string => 'lab_member_document_die';
add_filter('wp_die_handler', $dieHandler);
wp_set_current_user(0);
ob_start();

try {
    $_GET['assoc_document'] = (string) $memberDocument;
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
$code = (int) $GLOBALS['lab_download_status'];
$body = (string) $GLOBALS['lab_download_message'] . $sent;

if (
    ! $secretaryDenied
    || ! $boardDenied
    || ! $loggedOutDenied
    || ! $endedDenied
    || $memberBytes !== $pdf
    || $archive->read($publicDocument) !== $publicPdf
    || ! str_contains($publicHtml, 'LAB-MEMBER offentlig')
    || str_contains($publicHtml, 'LAB-MEMBER stadgar')
    || str_contains($publicHtml, 'assoc-private')
    || str_contains($publicHtml, 'document-')
    || str_contains($hiddenMemberHtml, 'LAB-MEMBER stadgar')
    || ! str_contains($memberHtml, 'LAB-MEMBER stadgar')
    || ! str_contains($memberHtml, 'assoc_document=' . $memberDocument)
    || str_contains($memberHtml, 'assoc-private')
    || str_contains($memberHtml, 'document-')
    || str_contains($endedHtml, 'LAB-MEMBER stadgar')
    || $code !== 403
    || str_contains($body, '%PDF')
    || str_contains($body, 'assoc-private')
    || $memberDocumentsUncacheable !== true
) {
    $fail('A member document was shown without an active membership or exposed its file.');
}

$cleanup();
\WP_CLI::success('A member document is readable only for a linked active membership.');
