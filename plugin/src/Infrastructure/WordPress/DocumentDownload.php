<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Document\DocumentFileType;

final class DocumentDownload
{
    public static function maybeSend(): void
    {
        if (! isset($_GET['assoc_document'])) {
            return;
        }

        $id = absint(wp_unslash($_GET['assoc_document']));

        if ($id < 1) {
            return;
        }

        try {
            $archive = WordpressDocuments::archive();
            $document = $archive->open($id);
            $bytes = $archive->read($id);
        } catch (NotAllowed) {
            wp_die(esc_html__('Du har inte behörighet att hämta dokumentet.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (\RuntimeException) {
            wp_die(esc_html__('Dokumentet kunde inte hämtas.', 'foreningsplugin'), '', ['response' => 404]);
        }

        $extension = DocumentFileType::extension($document->mediaType());
        nocache_headers();
        header('Content-Type: ' . $document->mediaType());
        header('Content-Disposition: attachment; filename="dokument.' . $extension . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }
}
