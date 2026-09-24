<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Membership\AssociationDate;

final class MemberCountBlock
{
    public static function register(): void
    {
        register_block_type('foreningsplugin/member-count', [
            'api_version' => 3,
            'title' => __('Medlemsantal', 'foreningsplugin'),
            'description' => __('Visar hur många aktiva medlemmar föreningen har, utan namn.', 'foreningsplugin'),
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
        $count = WordpressPeople::service()->publicMemberCount(AssociationDate::fromIso(wp_date('Y-m-d')));

        return '<p class="foreningsplugin-member-count">' . esc_html(sprintf(
            /* translators: %d: number of active members */
            __('Aktiva medlemmar: %d', 'foreningsplugin'),
            $count
        )) . '</p>';
    }
}
