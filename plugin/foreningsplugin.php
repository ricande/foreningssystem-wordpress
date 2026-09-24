<?php
/**
 * Plugin Name:       Föreningsplugin
 * Description:       Grund för föreningsplugin till WordPress.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Föreningsplugin
 * Text Domain:       foreningsplugin
 *
 * @package Foreningsplugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('FORENINGSPLUGIN_VERSION', '0.1.0');
define('FORENINGSPLUGIN_FILE', __FILE__);

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain(
        'foreningsplugin',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});

add_action('admin_menu', static function (): void {
    add_menu_page(
        __('Förening', 'foreningsplugin'),
        __('Förening', 'foreningsplugin'),
        'manage_options',
        'foreningsplugin',
        'foreningsplugin_render_admin_page',
        'dashicons-groups',
        26
    );
});

function foreningsplugin_render_admin_page(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Föreningsplugin', 'foreningsplugin') . '</h1>';
    echo '<p>' . esc_html__('Pluginet är aktivt.', 'foreningsplugin') . '</p>';
    echo '</div>';
}
