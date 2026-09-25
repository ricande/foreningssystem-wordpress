<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use PHPUnit\Framework\TestCase;

final class SetupPageNavMarkupTest extends TestCase
{
    public function test_back_and_skip_forms_are_emitted_outside_save_forms(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/src/Infrastructure/WordPress/SetupPage.php'
        );

        self::assertStringContainsString('private static function navAuxForms', $source);
        self::assertStringNotContainsString(
            "echo '<button type=\"submit\" form=\"assoc-setup-back-' . esc_attr(\$step) . '\">' . esc_html__('Back', 'foreningsplugin') . '</button> ';\n"
            . "            echo '<form id=\"assoc-setup-back-'",
            $source
        );
        self::assertMatchesRegularExpression(
            '/self::navButtons\(SetupStep::MINUTES, true, true\);.*?<\/p><\/form>\';\s*self::navAuxForms\(SetupStep::MINUTES, true, true\);/s',
            $source
        );
        self::assertMatchesRegularExpression(
            '/self::navButtons\(SetupStep::PRIVACY, true, true\);.*?<\/p><\/form>\';\s*self::navAuxForms\(SetupStep::PRIVACY, true, true\);/s',
            $source
        );
        self::assertMatchesRegularExpression(
            '/self::navButtons\(SetupStep::COMPLETE, true, false\);.*?<\/p><\/form>\';\s*self::navAuxForms\(SetupStep::COMPLETE, true, false\);/s',
            $source
        );
    }
}
