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
            'title' => __('Member count', 'foreningsplugin'),
            'description' => __('Shows how many people are members of a membership that is active today. Company memberships and contact-only people are not included.', 'foreningsplugin'),
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
            __('Active individual members: %d', 'foreningsplugin'),
            $count
        )) . '</p>';
    }
}
