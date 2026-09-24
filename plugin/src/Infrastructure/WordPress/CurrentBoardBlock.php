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
            'title' => __('Aktuell styrelse', 'foreningsplugin'),
            'description' => __('Visar namn, uppdrag och offentlig kontakt för den aktuella styrelsen.', 'foreningsplugin'),
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
            return '<p class="foreningsplugin-board">' . esc_html__('Ingen aktuell styrelse.', 'foreningsplugin') . '</p>';
        }

        $html = '<ul class="foreningsplugin-board">';

        foreach ($seats as $seat) {
            $html .= '<li><span class="foreningsplugin-board-name">' . esc_html($seat->personName()) . '</span> ';
            $html .= '<span class="foreningsplugin-board-role">' . esc_html($seat->roleName()) . '</span>';

            if ($seat->publicContact() !== '') {
                $html .= ' <span class="foreningsplugin-board-contact">' . esc_html($seat->publicContact()) . '</span>';
            }

            $html .= '</li>';
        }

        $html .= '</ul>';

        return $html;
    }
}
