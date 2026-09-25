<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use PHPUnit\Framework\TestCase;

final class SetupPageNavMarkupTest extends TestCase
{
    public function test_wizard_uses_sibling_nav_aux_forms_not_nested_forms(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/src/Infrastructure/WordPress/SetupPage.php'
        );

        self::assertStringContainsString('private static function primarySubmit', $source);
        self::assertStringContainsString('private static function continueStep', $source);
        self::assertStringContainsString('private static function navAuxForms', $source);
        self::assertStringNotContainsString('function continueOrSkip', $source);
        self::assertStringNotContainsString('function backOnly', $source);

        self::assertMatchesRegularExpression(
            '/self::primarySubmit\(\s*SetupStep::ASSOCIATION,/s',
            $source
        );
        self::assertMatchesRegularExpression(
            '/self::continueStep\(SetupStep::MEMBERSHIP\);/s',
            $source
        );
        self::assertMatchesRegularExpression(
            '/self::continueStep\(SetupStep::BOARD\);/s',
            $source
        );
        self::assertMatchesRegularExpression(
            '/self::continueStep\(SetupStep::MEETINGS\);/s',
            $source
        );
        self::assertMatchesRegularExpression(
            '/self::primarySubmit\(\s*SetupStep::MINUTES,/s',
            $source
        );
        self::assertMatchesRegularExpression(
            '/self::primarySubmit\(\s*SetupStep::PRIVACY,/s',
            $source
        );
        self::assertMatchesRegularExpression(
            '/self::primarySubmit\(\s*SetupStep::COMPLETE,/s',
            $source
        );

        // primarySubmit must close the open form before emitting aux forms.
        self::assertMatchesRegularExpression(
            "/echo '<\\/p><\\/form>';\\s*self::navAuxForms\\(/s",
            $source
        );

        // Regression: never emit a <form after a form= Back/Skip button without
        // closing the outer form first (the original minutes/privacy bug).
        self::assertDoesNotMatchRegularExpression(
            "/form=\"assoc-setup-back-' \\. esc_attr\\(\\\$step\\)[\\s\\S]{0,400}?echo '<form id=\"assoc-setup-back-'/",
            $source
        );
    }
}
