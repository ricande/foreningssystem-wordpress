<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Document\DocumentVisibility;

final class DocumentsPage
{
    public static function add(): void
    {
        self::guard('assoc_add_document');

        try {
            $bytes = self::uploadedBytes('document_file');
            WordpressDocuments::archive()->add(self::text('title'), $bytes, self::visibility('visibility'));
            self::redirect('document_added');
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to add documents.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect('document_invalid');
        }
    }

    public static function setVisibility(): void
    {
        self::guard('assoc_set_document_visibility');

        try {
            WordpressDocuments::archive()->setVisibility(self::integer('document_id'), self::visibility('visibility'));
            self::redirect('document_visibility');
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to change the document.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect('document_invalid');
        }
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::VIEW_BOARD_DOCUMENTS) && ! current_user_can(Capabilities::MANAGE_DOCUMENTS)) {
            wp_die(esc_html__('You do not have permission to view the documents.', 'foreningsplugin'), '', ['response' => 403]);
        }

        $canManage = current_user_can(Capabilities::MANAGE_DOCUMENTS);
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Documents', 'foreningsplugin') . '</h1>';
        echo '<p>' . esc_html__('Public documents are shown on the site. Member documents are shown to a logged-in person with an active membership. Internal documents are visible to the board. Administrator documents are visible only to someone who may manage documents. The download goes through a check, not through the file address.', 'foreningsplugin') . '</p>';
        self::notice();

        if ($canManage) {
            echo '<h2>' . esc_html__('New document', 'foreningsplugin') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
            echo '<input type="hidden" name="action" value="assoc_add_document">';
            wp_nonce_field('assoc_add_document');
            echo '<p><label>' . esc_html__('Title', 'foreningsplugin') . ' <input type="text" name="title" required maxlength="190"></label></p>';
            echo '<p><label>' . esc_html__('Visibility', 'foreningsplugin') . ' <select name="visibility">';
            echo '<option value="board">' . esc_html__('Internal', 'foreningsplugin') . '</option>';
            echo '<option value="member">' . esc_html__('Member', 'foreningsplugin') . '</option>';
            echo '<option value="administrator">' . esc_html__('Administrator', 'foreningsplugin') . '</option>';
            echo '<option value="public">' . esc_html__('Public', 'foreningsplugin') . '</option>';
            echo '</select></label></p>';
            echo '<p><label>' . esc_html__('PDF, JPEG, or PNG', 'foreningsplugin') . ' <input type="file" name="document_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></label></p>';
            submit_button(__('Save document', 'foreningsplugin'));
            echo '</form>';
        }

        echo '<h2>' . esc_html__('Saved documents', 'foreningsplugin') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Title', 'foreningsplugin') . '</th>';
        echo '<th>' . esc_html__('Visibility', 'foreningsplugin') . '</th>';
        echo '<th>' . esc_html__('Download', 'foreningsplugin') . '</th>';
        echo '</tr></thead><tbody>';

        foreach (WordpressDocuments::archive()->officerList() as $document) {
            echo '<tr>';
            echo '<td>' . esc_html($document->title()) . '</td>';
            echo '<td>' . esc_html(self::label($document->visibility())) . '</td>';
            echo '<td><a href="' . esc_url(PublicDocumentsBlock::downloadUrl($document)) . '">' . esc_html__('Download', 'foreningsplugin') . '</a>';

            if ($canManage) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-left:0.5em">';
                echo '<input type="hidden" name="action" value="assoc_set_document_visibility">';
                echo '<input type="hidden" name="document_id" value="' . esc_attr((string) $document->id()) . '">';
                wp_nonce_field('assoc_set_document_visibility');
                echo '<select name="visibility">';

                foreach (DocumentVisibility::cases() as $visibility) {
                    echo '<option value="' . esc_attr($visibility->value) . '"' . selected($visibility->value, $document->visibility()->value, false) . '>' . esc_html(self::label($visibility)) . '</option>';
                }

                echo '</select> ';
                submit_button(__('Save visibility', 'foreningsplugin'), 'secondary', 'submit', false);
                echo '</form>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table></div>';
    }

    private static function guard(string $nonce): void
    {
        if (! current_user_can(Capabilities::MANAGE_DOCUMENTS)) {
            wp_die(esc_html__('You do not have permission to change the documents.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    private static function label(DocumentVisibility $visibility): string
    {
        return match ($visibility) {
            DocumentVisibility::Public => __('Public', 'foreningsplugin'),
            DocumentVisibility::Member => __('Member', 'foreningsplugin'),
            DocumentVisibility::Administrator => __('Administrator', 'foreningsplugin'),
            DocumentVisibility::Board => __('Internal', 'foreningsplugin'),
        };
    }

    private static function visibility(string $key): DocumentVisibility
    {
        $value = DocumentVisibility::tryFrom(self::text($key));

        if (! $value instanceof DocumentVisibility) {
            throw new \InvalidArgumentException('The document visibility is not valid.');
        }

        return $value;
    }

    private static function uploadedBytes(string $key): string
    {
        return UploadedFile::bytes($key, 'The document was not uploaded.');
    }

    private static function text(string $key): string
    {
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
    }

    private static function integer(string $key): int
    {
        return isset($_POST[$key]) ? absint(wp_unslash($_POST[$key])) : 0;
    }

    private static function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg('assoc_notice', $notice, admin_url('admin.php?page=foreningsplugin-documents')));
        exit;
    }

    private static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';
        $messages = [
            'document_added' => __('The document is saved.', 'foreningsplugin'),
            'document_visibility' => __('The visibility is changed. The file is the same.', 'foreningsplugin'),
            'document_invalid' => __('Check the title, the visibility, and that the file is a PDF, JPEG, or PNG.', 'foreningsplugin'),
        ];

        if (! isset($messages[$notice])) {
            return;
        }

        $class = $notice === 'document_invalid' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }
}
