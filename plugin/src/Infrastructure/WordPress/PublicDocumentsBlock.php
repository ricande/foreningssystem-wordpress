<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Document\ListedDocument;
use Foreningssystem\Domain\Document\DocumentFileType;

final class PublicDocumentsBlock
{
    public static function register(): void
    {
        register_block_type('foreningsplugin/documents', [
            'api_version' => 3,
            'title' => __('Document archive', 'foreningsplugin'),
            'description' => __('Shows public documents. The download goes through a check.', 'foreningsplugin'),
            'category' => 'widgets',
            'icon' => 'media-document',
            'textdomain' => 'foreningsplugin',
            'supports' => [
                'html' => false,
            ],
            'render_callback' => [self::class, 'render'],
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = [], string $content = ''): string
    {
        unset($attributes, $content);
        $documents = WordpressDocuments::archive()->publicList();

        if ($documents === []) {
            return '<p class="foreningsplugin-documents">' . esc_html__('No public documents.', 'foreningsplugin') . '</p>';
        }

        $html = '<ul class="foreningsplugin-documents">';

        foreach ($documents as $document) {
            $html .= '<li><a href="' . esc_url(self::downloadUrl($document)) . '">' . esc_html($document->title()) . '</a></li>';
        }

        $html .= '</ul>';

        return $html;
    }

    public static function downloadUrl(ListedDocument $document): string
    {
        return add_query_arg('assoc_document', (string) $document->id(), home_url('/'));
    }

    public static function extension(ListedDocument $document): string
    {
        return DocumentFileType::extension($document->mediaType());
    }
}
