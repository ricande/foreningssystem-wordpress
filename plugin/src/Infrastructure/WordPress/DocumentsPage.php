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
            WordpressDocuments::archive()->add(self::text('title'), self::uploadedBytes('document_file'), self::visibility('visibility'));
            self::redirect('document_added');
        } catch (NotAllowed) {
            wp_die(esc_html__('Du har inte behörighet att lägga till dokument.', 'foreningsplugin'), '', ['response' => 403]);
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
            wp_die(esc_html__('Du har inte behörighet att ändra dokumentet.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect('document_invalid');
        }
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::VIEW_BOARD_DOCUMENTS) && ! current_user_can(Capabilities::MANAGE_DOCUMENTS)) {
            wp_die(esc_html__('Du har inte behörighet att se dokumenten.', 'foreningsplugin'), '', ['response' => 403]);
        }

        $canManage = current_user_can(Capabilities::MANAGE_DOCUMENTS);
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Dokument', 'foreningsplugin') . '</h1>';
        echo '<p>' . esc_html__('Offentliga dokument visas på webbplatsen. Medlemsdokument visas för en inloggad person med aktivt medlemskap. Interna dokument syns för styrelsen. Administratörsdokument syns bara för den som får hantera dokument. Hämtningen går via en kontroll, inte via filens adress.', 'foreningsplugin') . '</p>';
        self::notice();

        if ($canManage) {
            echo '<h2>' . esc_html__('Nytt dokument', 'foreningsplugin') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
            echo '<input type="hidden" name="action" value="assoc_add_document">';
            wp_nonce_field('assoc_add_document');
            echo '<p><label>' . esc_html__('Titel', 'foreningsplugin') . ' <input type="text" name="title" required maxlength="190"></label></p>';
            echo '<p><label>' . esc_html__('Synlighet', 'foreningsplugin') . ' <select name="visibility">';
            echo '<option value="board">' . esc_html__('Intern', 'foreningsplugin') . '</option>';
            echo '<option value="member">' . esc_html__('Medlem', 'foreningsplugin') . '</option>';
            echo '<option value="administrator">' . esc_html__('Administratör', 'foreningsplugin') . '</option>';
            echo '<option value="public">' . esc_html__('Offentlig', 'foreningsplugin') . '</option>';
            echo '</select></label></p>';
            echo '<p><label>' . esc_html__('PDF, JPEG eller PNG', 'foreningsplugin') . ' <input type="file" name="document_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></label></p>';
            submit_button(__('Spara dokument', 'foreningsplugin'));
            echo '</form>';
        }

        echo '<h2>' . esc_html__('Sparade dokument', 'foreningsplugin') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Titel', 'foreningsplugin') . '</th>';
        echo '<th>' . esc_html__('Synlighet', 'foreningsplugin') . '</th>';
        echo '<th>' . esc_html__('Hämta', 'foreningsplugin') . '</th>';
        echo '</tr></thead><tbody>';

        foreach (WordpressDocuments::archive()->officerList() as $document) {
            echo '<tr>';
            echo '<td>' . esc_html($document->title()) . '</td>';
            echo '<td>' . esc_html(self::label($document->visibility())) . '</td>';
            echo '<td><a href="' . esc_url(PublicDocumentsBlock::downloadUrl($document)) . '">' . esc_html__('Hämta', 'foreningsplugin') . '</a>';

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
                submit_button(__('Spara synlighet', 'foreningsplugin'), 'secondary', 'submit', false);
                echo '</form>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table></div>';
    }

    private static function guard(string $nonce): void
    {
        if (! current_user_can(Capabilities::MANAGE_DOCUMENTS)) {
            wp_die(esc_html__('Du har inte behörighet att ändra dokumenten.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    private static function label(DocumentVisibility $visibility): string
    {
        return match ($visibility) {
            DocumentVisibility::Public => __('Offentlig', 'foreningsplugin'),
            DocumentVisibility::Member => __('Medlem', 'foreningsplugin'),
            DocumentVisibility::Administrator => __('Administratör', 'foreningsplugin'),
            DocumentVisibility::Board => __('Intern', 'foreningsplugin'),
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
        $file = $_FILES[$key] ?? null;

        if (! is_array($file) || ! isset($file['tmp_name'], $file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('The document was not uploaded.');
        }

        $tmp = (string) $file['tmp_name'];

        if (! is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('The document was not uploaded.');
        }

        $bytes = file_get_contents($tmp);

        if (! is_string($bytes)) {
            throw new \InvalidArgumentException('The document was not uploaded.');
        }

        return $bytes;
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
            'document_added' => __('Dokumentet är sparat.', 'foreningsplugin'),
            'document_visibility' => __('Synligheten är ändrad. Filen är densamma.', 'foreningsplugin'),
            'document_invalid' => __('Kontrollera titel, synlighet och att filen är en PDF, JPEG eller PNG.', 'foreningsplugin'),
        ];

        if (! isset($messages[$notice])) {
            return;
        }

        $class = $notice === 'document_invalid' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }
}
