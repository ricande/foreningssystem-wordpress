<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Membership\AssociationDate;

final class CurrentBoardBlock
{
    public static function register(): void
    {
        register_block_type('foreningsplugin/current-board', [
            'api_version' => 3,
            'title' => __('Current board', 'foreningsplugin'),
            'description' => __('Shows the name, role, and public contact for the current board.', 'foreningsplugin'),
            'category' => 'widgets',
            'icon' => 'groups',
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
        $seats = WordpressBoard::service()->currentPublic(AssociationDate::fromIso(wp_date('Y-m-d')));

        if ($seats === []) {
            return '<p class="foreningsplugin-board">' . esc_html__('No current board.', 'foreningsplugin') . '</p>';
        }

        $html = '<ul class="foreningsplugin-board">';

        foreach ($seats as $seat) {
            $html .= '<li><span class="foreningsplugin-board-name">' . esc_html($seat->personName()) . '</span> ';
            $html .= '<span class="foreningsplugin-board-role">' . esc_html(BoardScreen::roleLabel($seat->roleSlug(), $seat->roleName())) . '</span>';

            if ($seat->publicContact() !== '') {
                $html .= ' <span class="foreningsplugin-board-contact">' . esc_html($seat->publicContact()) . '</span>';
            }

            $html .= '</li>';
        }

        $html .= '</ul>';

        return $html;
    }
}
