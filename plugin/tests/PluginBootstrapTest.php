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
}
