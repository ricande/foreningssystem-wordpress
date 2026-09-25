<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Infrastructure\WordPress\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginBootstrapTest extends TestCase
{
    public function test_autoload_resolves_the_plugin_class(): void
    {
        self::assertSame('0.1.0', Plugin::VERSION);
    }

    public function test_plugin_header_declares_gpl(): void
    {
        $header = (string) file_get_contents(dirname(__DIR__) . '/foreningsplugin.php');

        self::assertStringContainsString('Plugin Name:       Föreningsplugin', $header);
        self::assertStringContainsString('License:           GPL-2.0-or-later', $header);
        self::assertStringContainsString("require_once __DIR__ . '/autoload.php';", $header);
    }

    public function test_translations_load_on_init(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/Plugin.php');

        self::assertDoesNotMatchRegularExpression("/add_action\\(\\s*'plugins_loaded'/", $source);

        $init = strpos($source, "add_action('init', static function () use (\$pluginFile): void {");
        $load = strpos($source, 'load_plugin_textdomain(');

        self::assertNotFalse($init);
        self::assertNotFalse($load);
        self::assertLessThan($load, $init);
    }
}
