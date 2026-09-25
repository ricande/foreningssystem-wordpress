<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use PHPUnit\Framework\TestCase;

final class BoardPageNavMarkupTest extends TestCase
{
    public function test_wizard_person_and_confirm_do_not_nest_forms(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/src/Infrastructure/WordPress/BoardScreen.php'
        );

        self::assertStringContainsString('assoc-board-wizard-task', $source);
        self::assertStringContainsString('assoc-board-wizard-role', $source);
        self::assertStringContainsString('assoc-board-wizard-person', $source);
        self::assertStringContainsString('assoc-board-wizard-confirm', $source);
        self::assertStringContainsString('assoc-board-wizard-done', $source);
        self::assertStringContainsString('assoc-board-history-link', $source);
        self::assertStringContainsString("assoc_view' => 'history'", $source);
        self::assertStringContainsString('method="get"', $source);

        $person = self::extractMethod($source, 'placeContinueForm');
        self::assertNotSame('', $person);
        self::assertStringContainsString("method=\"get\"", $person);
        self::assertSame(1, substr_count($person, '<form'));
        self::assertSame(1, substr_count($person, '</form>'));

        $confirm = self::extractMethod($source, 'confirmStep');
        self::assertNotSame('', $confirm);
        self::assertStringContainsString('assoc_place_assignment', $confirm);
        self::assertStringContainsString('assoc_end_assignment', $confirm);
        self::assertStringContainsString('assoc_cancel_assignment', $confirm);
        // Back is a link, not a nested form inside the mutation form.
        self::assertStringContainsString('self::backLink(', $confirm);
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
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }
}
