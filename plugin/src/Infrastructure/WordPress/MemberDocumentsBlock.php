<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

final class MemberDocumentsBlock
{
    public static function register(): void
    {
        register_block_type('foreningsplugin/member-documents', [
            'api_version' => 3,
            'title' => __('Medlemsdokument', 'foreningsplugin'),
            'description' => __('Visar dokument för en inloggad person med aktivt medlemskap. Hämtningen går via en kontroll.', 'foreningsplugin'),
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
        $documents = WordpressDocuments::archive()->memberList();

        if ($documents === null) {
            return '<p class="foreningsplugin-member-documents">' . esc_html__('Logga in med ett aktivt medlemskap för att se medlemsdokument.', 'foreningsplugin') . '</p>';
        }

        if ($documents === []) {
            return '<p class="foreningsplugin-member-documents">' . esc_html__('Inga medlemsdokument.', 'foreningsplugin') . '</p>';
        }

        $html = '<ul class="foreningsplugin-member-documents">';

        foreach ($documents as $document) {
            $html .= '<li><a href="' . esc_url(PublicDocumentsBlock::downloadUrl($document)) . '">' . esc_html($document->title()) . '</a></li>';
        }

        $html .= '</ul>';

        return $html;
    }
}