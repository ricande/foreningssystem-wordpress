<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

final class Plugin
{
    public const VERSION = '0.1.0';

    public static function register(string $pluginFile): void
    {
        add_action('plugins_loaded', static function () use ($pluginFile): void {
            load_plugin_textdomain(
                'foreningsplugin',
                false,
                dirname(plugin_basename($pluginFile)) . '/languages'
            );
        });

        add_action('admin_menu', [self::class, 'registerAdminMenu']);
    }

    public static function registerAdminMenu(): void
    {
        add_menu_page(
            __('Förening', 'foreningsplugin'),
            __('Förening', 'foreningsplugin'),
            'manage_options',
            'foreningsplugin',
            [self::class, 'renderAdminPage'],
            'dashicons-groups',
            26
        );
    }

    public static function renderAdminPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Föreningsplugin', 'foreningsplugin') . '</h1>';
        echo '<p>' . esc_html__('Pluginet är aktivt.', 'foreningsplugin') . '</p>';
        echo '</div>';
    }
}
