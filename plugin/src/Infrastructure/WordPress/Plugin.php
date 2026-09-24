<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Infrastructure\Persistence\MigrationException;

final class Plugin
{
    public const VERSION = '0.1.0';

    public static function register(string $pluginFile): void
    {
        register_activation_hook($pluginFile, [self::class, 'activate']);

        add_action('plugins_loaded', static function () use ($pluginFile): void {
            load_plugin_textdomain(
                'foreningsplugin',
                false,
                dirname(plugin_basename($pluginFile)) . '/languages'
            );

            if (defined('WP_CLI') && WP_CLI) {
                WordpressMigrations::migrateIfNeeded();
                WordpressAccess::sync();
            }
        });

        add_action('admin_init', [self::class, 'migrateInAdmin']);
        add_action('admin_menu', [self::class, 'registerAdminMenu']);

        if (defined('WP_CLI') && WP_CLI) {
            Cli::register();
        }
    }

    public static function activate(): void
    {
        WordpressMigrations::runner()->migrate();
        WordpressAccess::sync();
    }

    public static function migrateInAdmin(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        try {
            WordpressMigrations::migrateIfNeeded();
            WordpressAccess::sync();
        } catch (MigrationException $error) {
            add_action('admin_notices', static function () use ($error): void {
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html($error->getMessage())
                );
            });
        }
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
        echo '<p>' . esc_html(sprintf(
            /* translators: %d: schema version */
            __('Databasschema: %d', 'foreningsplugin'),
            (int) get_option(WordpressSchemaVersionStore::OPTION, 0)
        )) . '</p>';
        echo '</div>';
    }
}
