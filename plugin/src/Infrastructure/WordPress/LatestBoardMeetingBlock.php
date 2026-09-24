<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Meeting\PublicBoardMeeting;

final class LatestBoardMeetingBlock
{
    public static function register(): void
    {
        register_block_type('foreningsplugin/latest-board-meeting', [
            'api_version' => 3,
            'title' => __('Senaste styrelsemötet', 'foreningsplugin'),
            'description' => __('Visar rubrik, datum och plats för det senaste styrelsemötet med ett publicerat protokoll.', 'foreningsplugin'),
            'category' => 'widgets',
            'icon' => 'calendar-alt',
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
        $meeting = WordpressMeetings::publication()->latestBoardMeeting();

        if (! $meeting instanceof PublicBoardMeeting) {
            return '<p class="foreningsplugin-board-meeting">' . esc_html__('Inget publicerat styrelsemöte.', 'foreningsplugin') . '</p>';
        }

        $html = '<article class="foreningsplugin-board-meeting">';
        $html .= '<h2>' . esc_html($meeting->title()) . '</h2>';
        $html .= '<p>' . esc_html($meeting->date()) . '</p>';

        if ($meeting->place() !== '') {
            $html .= '<p>' . esc_html($meeting->place()) . '</p>';
        }

        $html .= '</article>';

        return $html;
    }
}
