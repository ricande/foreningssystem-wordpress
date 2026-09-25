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

        $navButtons = self::extractMethod($source, 'navButtons');
        self::assertNotSame('', $navButtons);
        self::assertStringContainsString('form="assoc-setup-back-', $navButtons);
        self::assertStringNotContainsString('<form', $navButtons);

        $primarySubmit = self::extractMethod($source, 'primarySubmit');
        self::assertStringContainsString("</p></form>';", $primarySubmit);
        self::assertStringContainsString('self::navAuxForms(', $primarySubmit);
        $closePos = strpos($primarySubmit, "</p></form>';");
        $auxPos = strpos($primarySubmit, 'self::navAuxForms(');
        self::assertNotFalse($closePos);
        self::assertNotFalse($auxPos);
        self::assertGreaterThan($closePos, $auxPos);
    }

    private static function extractMethod(string $source, string $name): string
    {
        $start = strpos($source, 'private static function ' . $name . '(');
        if ($start === false) {
            return '';
        }

        $brace = strpos($source, '{', $start);
        if ($brace === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($source);
        for ($i = $brace; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }

        return '';
    }
}
