<?php
/**
 * Plugin Name:       Föreningsplugin
 * Description:       Grund för föreningsplugin till WordPress.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Tested up to:      7.1
 * Author:            Föreningsplugin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       foreningsplugin
 * Domain Path:       /languages
 *
 * @package Foreningsplugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

define('FORENINGSPLUGIN_VERSION', \Foreningssystem\Infrastructure\WordPress\Plugin::VERSION);
define('FORENINGSPLUGIN_FILE', __FILE__);

\Foreningssystem\Infrastructure\WordPress\Plugin::register(__FILE__);
