<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

final class MemberDocumentsBlock
{
    public static function register(): void
    {
        register_block_type('foreningsplugin/member-documents', [
            'api_version' => 3,
            'title' => __('Member documents', 'foreningsplugin'),
            'description' => __('Shows documents for a logged-in person with an active membership. The download goes through a check.', 'foreningsplugin'),
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
     * The list depends on the logged-in visitor's membership, so the page it renders on is
     * personalized even when the member area block is not on it.
     *
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = [], string $content = ''): string
    {
        unset($attributes, $content);
        PersonalizedOutput::doNotCache();
        $documents = WordpressDocuments::archive()->memberList();

        if ($documents === null) {
            return '<p class="foreningsplugin-member-documents">' . esc_html__('Log in with an active membership to see member documents.', 'foreningsplugin') . '</p>';
        }

        if ($documents === []) {
            return '<p class="foreningsplugin-member-documents">' . esc_html__('No member documents.', 'foreningsplugin') . '</p>';
        }

        $html = '<ul class="foreningsplugin-member-documents">';

        foreach ($documents as $document) {
            $html .= '<li><a href="' . esc_url(PublicDocumentsBlock::downloadUrl($document)) . '">' . esc_html($document->title()) . '</a></li>';
        }

        $html .= '</ul>';

        return $html;
    }
}