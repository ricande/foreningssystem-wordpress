<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use PHPUnit\Framework\TestCase;

final class MeetingWorkspaceScreenMarkupTest extends TestCase
{
    public function test_in_progress_workspace_matches_setup_guide_flow(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/src/Infrastructure/WordPress/MeetingWorkspaceScreen.php'
        );
        $actions = (string) file_get_contents(
            dirname(__DIR__) . '/src/Infrastructure/WordPress/MeetingDetailPage.php'
        );

        self::assertMatchesRegularExpression(
            '/status\(\) !== MeetingStatus::InProgress\) \{\s*echo \'<p><a[^;]+All meetings/s',
            $source
        );
        self::assertStringContainsString("wrap' . (\$meeting instanceof Meeting && \$meeting->status() === MeetingStatus::InProgress ? ' assoc-setup' : '')", $source);
        self::assertStringContainsString('self::agendaProgress(', $source);

        $primary = self::extractMethod($source, 'primaryAction');
        self::assertNotSame('', $primary);
        self::assertStringNotContainsString('MeetingStatus::InProgress', $primary);
        self::assertStringContainsString('assoc_mark_meeting_held', $primary);

        $progress = self::extractMethod($source, 'agendaProgress');
        self::assertNotSame('', $progress);
        self::assertStringContainsString('assoc-setup-progress-status', $progress);
        self::assertStringContainsString("__('Item %1\$d of %2\$d', 'foreningsplugin')", $progress);
        self::assertStringContainsString('assoc-meeting-steps', $progress);

        $conduct = self::extractMethod($source, 'conduct');
        self::assertNotSame('', $conduct);
        self::assertStringContainsString("echo '<h2>'", $conduct);
        self::assertStringNotContainsString('assoc-setup-progress-status', $conduct);
        self::assertStringContainsString("name=\"end_meeting\"", $conduct);
        self::assertStringContainsString("__('Continue', 'foreningsplugin')", $conduct);

        $render = self::extractMethod($source, 'render');
        self::assertNotSame('', $render);
        $h1Pos = strpos($render, "echo '<h1>'");
        $progressPos = strpos($render, 'self::agendaProgress(');
        self::assertNotFalse($h1Pos);
        self::assertNotFalse($progressPos);
        self::assertLessThan($progressPos, $h1Pos);

        $end = self::extractMethod($source, 'endMeeting');
        self::assertNotSame('', $end);
        self::assertStringContainsString("__('End meeting', 'foreningsplugin')", $end);
        self::assertStringContainsString("__('Are you sure?', 'foreningsplugin')", $end);
        self::assertStringContainsString("__('Yes', 'foreningsplugin')", $end);
        self::assertStringContainsString("__('No', 'foreningsplugin')", $end);
        self::assertStringContainsString("name=\"confirm\" value=\"1\"", $end);
        self::assertStringContainsString('assoc_mark_meeting_held', $end);
        self::assertStringNotContainsString('type="checkbox"', $end);

        self::assertStringContainsString('function redirectEnd', $actions);
        self::assertStringContainsString("'assoc_end_meeting' => '1'", $actions);
        self::assertStringContainsString("self::text('end_meeting') === '1'", $actions);
    }

    private static function extractMethod(string $source, string $name): string
    {
        $candidates = [
            'public static function ' . $name . '(',
            'private static function ' . $name . '(',
        ];
        $start = false;
        foreach ($candidates as $needle) {
            $start = strpos($source, $needle);
            if ($start !== false) {
                break;
            }
        }
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
