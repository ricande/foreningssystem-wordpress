<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Meeting\PublicMinutes;

final class LatestMinutesBlock
{
    public static function register(): void
    {
        register_block_type('foreningsplugin/latest-minutes', [
            'api_version' => 3,
            'title' => __('Latest minutes', 'foreningsplugin'),
            'description' => __('Shows the latest published locked revision.', 'foreningsplugin'),
            'category' => 'widgets',
            'icon' => 'media-text',
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
        $published = WordpressMeetings::publication()->latest();

        if (! $published instanceof PublicMinutes) {
            return '<p class="foreningsplugin-minutes">' . esc_html__('No published minutes.', 'foreningsplugin') . '</p>';
        }

        $meeting = $published->meeting();
        $html = '<article class="foreningsplugin-minutes">';
        $html .= '<h2>' . esc_html($meeting->title()) . '</h2>';
        $html .= '<p>' . esc_html($meeting->startsAt()->date()) . '</p>';
        $html .= '<div class="foreningsplugin-minutes-body">' . nl2br(esc_html($published->revision()->body()), false) . '</div>';
        $html .= '</article>';

        return $html;
    }
}
