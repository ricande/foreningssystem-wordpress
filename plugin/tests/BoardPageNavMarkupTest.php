<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Infrastructure\WordPress\BoardScreen;
use Foreningssystem\Tests\Support\WordPressRequest;
use PHPUnit\Framework\TestCase;

final class BoardPageNavMarkupTest extends TestCase
{
    protected function setUp(): void
    {
        WordPressRequest::reset();
    }

    protected function tearDown(): void
    {
        WordPressRequest::reset();
    }

    public function test_confirming_the_first_holder_does_not_read_a_missing_current_holder(): void
    {
        $html = $this->renderConfirm([]);

        self::assertStringContainsString('id="assoc-board-wizard-confirm"', $html);
        self::assertStringContainsString('Confirm: add Anna Lindberg as Chair starting 2024-01-01.', $html);
        self::assertStringContainsString('name="person_id" value="31"', $html);
        self::assertStringContainsString('class="assoc-place-form"', $html);
        self::assertStringNotContainsString('class="assoc-replace-form"', $html);
    }

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

    /**
     * @param list<\Foreningssystem\Application\Board\BoardSeat> $seats
     */
    private function renderConfirm(array $seats): string
    {
        ob_start();
        BoardScreen::render(
            $seats,
            [new BoardRole(1, 'chair', 'Ordförande', false, 10)],
            [[
                'person_id' => 31,
                'name' => 'Anna Lindberg',
                'coverage' => 'active',
                'coverage_on' => null,
            ]],
            true,
            '',
            [
                'step' => 'confirm',
                'task' => 'add',
                'role_id' => 1,
                'person_id' => 31,
                'started_on' => '2024-01-01',
                'ended_on' => '2029-02-01',
                'public_contact' => '',
                'term_label' => '',
            ]
        );

        return (string) ob_get_clean();
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
