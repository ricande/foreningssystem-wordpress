<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Setup\SetupState;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Infrastructure\WordPress\Plugin;
use PHPUnit\Framework\TestCase;

final class SetupSuccessNoticeTest extends TestCase
{
    public function test_full_admin_sees_all_applicable_next_step_links(): void
    {
        $allowed = [
            Capabilities::MANAGE_ASSOCIATION,
            Capabilities::EDIT_MEMBERS,
            Capabilities::MANAGE_BOARD,
            Capabilities::MANAGE_MEETINGS,
        ];
        $steps = Plugin::setupSuccessNextSteps($this->can($allowed));

        self::assertSame([
            ['capability' => Capabilities::EDIT_MEMBERS, 'page' => 'foreningsplugin-members'],
            ['capability' => Capabilities::MANAGE_BOARD, 'page' => 'foreningsplugin-board'],
            ['capability' => Capabilities::MANAGE_MEETINGS, 'page' => 'foreningsplugin-meetings'],
        ], $steps);
        self::assertSame(
            ['foreningsplugin-members', 'foreningsplugin-board', 'foreningsplugin-meetings'],
            array_column($steps, 'page')
        );
    }

    public function test_settings_only_sees_no_unauthorized_operational_links(): void
    {
        $allowed = [
            Capabilities::ACCESS_ASSOCIATION,
            Capabilities::MANAGE_ASSOCIATION,
        ];
        $steps = Plugin::setupSuccessNextSteps($this->can($allowed));

        self::assertSame([], $steps);
        self::assertNotContains(Capabilities::EDIT_MEMBERS, $allowed);
        self::assertNotContains(Capabilities::MANAGE_BOARD, $allowed);
        self::assertNotContains(Capabilities::MANAGE_MEETINGS, $allowed);

        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/Plugin.php');
        $notice = strpos($source, 'public static function setupSuccessNotice(): void');
        $next = strpos($source, 'public static function setupSuccessNextSteps');

        self::assertNotFalse($notice);
        self::assertNotFalse($next);
        $body = substr($source, $notice, $next - $notice);
        self::assertStringContainsString("if (\$steps !== [])", $body);
        self::assertStringContainsString("echo '<ul>';", $body);
        self::assertDoesNotMatchRegularExpression("/\\. '\\<\\/p\\>\\<ul\\>';/", $body);
    }

    public function test_member_management_sees_only_the_member_link(): void
    {
        $steps = Plugin::setupSuccessNextSteps($this->can([
            Capabilities::MANAGE_ASSOCIATION,
            Capabilities::EDIT_MEMBERS,
            Capabilities::VIEW_MEMBERS,
        ]));

        self::assertSame([
            ['capability' => Capabilities::EDIT_MEMBERS, 'page' => 'foreningsplugin-members'],
        ], $steps);
    }

    public function test_board_management_sees_only_the_board_link(): void
    {
        $steps = Plugin::setupSuccessNextSteps($this->can([
            Capabilities::MANAGE_ASSOCIATION,
            Capabilities::MANAGE_BOARD,
            Capabilities::VIEW_MEMBERS,
        ]));

        self::assertSame([
            ['capability' => Capabilities::MANAGE_BOARD, 'page' => 'foreningsplugin-board'],
        ], $steps);
    }

    public function test_meeting_management_sees_only_the_meeting_link(): void
    {
        $steps = Plugin::setupSuccessNextSteps($this->can([
            Capabilities::MANAGE_ASSOCIATION,
            Capabilities::MANAGE_MEETINGS,
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ]));

        self::assertSame([
            ['capability' => Capabilities::MANAGE_MEETINGS, 'page' => 'foreningsplugin-meetings'],
        ], $steps);
    }

    public function test_rendering_grants_and_changes_no_capabilities(): void
    {
        $allowed = [
            Capabilities::MANAGE_ASSOCIATION,
            Capabilities::EDIT_MEMBERS,
        ];
        $before = $allowed;
        $checked = [];
        $can = static function (string $capability) use (&$allowed, &$checked): bool {
            $checked[] = $capability;

            return in_array($capability, $allowed, true);
        };

        $steps = Plugin::setupSuccessNextSteps($can);

        self::assertSame($before, $allowed);
        self::assertSame([Capabilities::EDIT_MEMBERS], array_column($steps, 'capability'));
        self::assertSame(
            [Capabilities::EDIT_MEMBERS, Capabilities::MANAGE_BOARD, Capabilities::MANAGE_MEETINGS],
            $checked
        );
        self::assertNotContains(Capabilities::MANAGE_BOARD, $allowed);
        self::assertNotContains(Capabilities::MANAGE_MEETINGS, $allowed);
        self::assertSame(Capabilities::all(), Capabilities::all());
    }

    public function test_setup_remains_version_one(): void
    {
        self::assertSame(1, SetupState::CURRENT_SETUP_VERSION);
        self::assertSame('0.1.0', Plugin::VERSION);

        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/Plugin.php');

        self::assertStringContainsString('setupSuccessNextSteps', $source);
        self::assertStringContainsString('Capabilities::EDIT_MEMBERS', $source);
        self::assertStringContainsString('Capabilities::MANAGE_BOARD', $source);
        self::assertStringContainsString('Capabilities::MANAGE_MEETINGS', $source);
        self::assertMatchesRegularExpression(
            '/if \(\$steps !== \[\]\) \{\s*echo \'<ul>\'/s',
            $source
        );
        self::assertDoesNotMatchRegularExpression(
            '/\. \'<\/p><ul>\';\s*echo \'<li><a href="\'\s*\.\s*esc_url\(admin_url\(\'admin\.php\?page=foreningsplugin-members\'\)\)/',
            $source
        );
    }

    public function test_read_only_member_or_meeting_view_is_not_enough_for_next_steps(): void
    {
        self::assertSame([], Plugin::setupSuccessNextSteps($this->can([
            Capabilities::MANAGE_ASSOCIATION,
            Capabilities::VIEW_MEMBERS,
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ])));
    }

    /**
     * @param list<string> $allowed
     * @return callable(string): bool
     */
    private function can(array $allowed): callable
    {
        return static function (string $capability) use ($allowed): bool {
            return in_array($capability, $allowed, true);
        };
    }
}
