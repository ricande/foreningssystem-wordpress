<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Infrastructure\WordPress\MinutesRoleChoices;
use Foreningssystem\Infrastructure\WordPress\SetupPage;
use Foreningssystem\Tests\Support\AdminHalt;
use Foreningssystem\Tests\Support\WordPressRequest;
use PHPUnit\Framework\TestCase;

final class SetupStructureRemovalTest extends TestCase
{
    protected function setUp(): void
    {
        WordPressRequest::reset();
    }

    protected function tearDown(): void
    {
        WordPressRequest::reset();
    }

    public function test_removing_a_board_role_in_setup_requires_capability_and_nonce(): void
    {
        try {
            SetupPage::removeBoardRole();
            $this->fail('Removal was allowed without manage_association.');
        } catch (AdminHalt $halt) {
            $this->assertSame(403, $halt->status());
            $this->assertStringNotContainsString('check_admin_referer', implode(' ', WordPressRequest::$calls));
        }

        WordPressRequest::allow(Capabilities::MANAGE_ASSOCIATION);

        try {
            SetupPage::removeBoardRole();
            $this->fail('Removal was allowed without a nonce.');
        } catch (AdminHalt $halt) {
            $this->assertSame(403, $halt->status());
        }
    }

    public function test_removing_a_meeting_type_in_setup_requires_capability_and_nonce(): void
    {
        try {
            SetupPage::removeMeetingType();
            $this->fail('Removal was allowed without manage_association.');
        } catch (AdminHalt $halt) {
            $this->assertSame(403, $halt->status());
            $this->assertStringNotContainsString('check_admin_referer', implode(' ', WordPressRequest::$calls));
        }

        WordPressRequest::allow(Capabilities::MANAGE_ASSOCIATION);

        try {
            SetupPage::removeMeetingType();
            $this->fail('Removal was allowed without a nonce.');
        } catch (AdminHalt $halt) {
            $this->assertSame(403, $halt->status());
        }
    }

    public function test_setup_removal_calls_the_definition_service(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/SetupPage.php');
        $settings = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/AssociationSettingsPage.php');

        self::assertStringContainsString('boardRoles()->remove(', $source);
        self::assertStringContainsString('meetingTypes()->remove(', $source);
        self::assertStringContainsString('boardRoles()->remove(', $settings);
        self::assertStringContainsString('meetingTypes()->remove(', $settings);
        self::assertStringNotContainsString('DELETE FROM', $source);
        self::assertStringNotContainsString('DELETE FROM', $settings);
    }

    public function test_custom_board_roles_are_choices_for_finalizing_and_publishing(): void
    {
        $material = new BoardRole(42, 'custom_material_manager', 'Material manager', false, 70);
        $chair = new BoardRole(1, 'chair', 'Ordförande', false, 10);
        $labels = MinutesRoleChoices::labels([$chair, $material]);
        $extra = MinutesRoleChoices::extraRoles([$chair, $material]);

        self::assertSame('Material manager', $labels['custom_material_manager']);
        self::assertArrayHasKey(RoleBundles::CHAIR, $labels);
        self::assertArrayHasKey(RoleBundles::SECRETARY, $labels);
        self::assertArrayNotHasKey('chair', $labels);
        self::assertSame(['custom_material_manager' => 42], $extra);

        $setup = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/SetupPage.php');
        $lock = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/MinutesLockPage.php');
        $publish = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/MinutesPublishPage.php');
        $minutes = self::extractMethod($setup, 'minutes');
        $labels = self::extractMethod($setup, 'roleLabels');

        self::assertStringContainsString('self::roleLabels()', $minutes);
        self::assertStringContainsString('MinutesRoleChoices::labels', $labels);
        self::assertStringContainsString('name="lock_roles[]"', $minutes);
        self::assertStringContainsString('name="publish_roles[]"', $minutes);
        self::assertStringContainsString('MinutesRoleChoices::labels', $lock);
        self::assertStringContainsString('MinutesRoleChoices::labels', $publish);
        self::assertStringContainsString(
            'This permission is not the same as being chosen to adjust a particular meeting.',
            $minutes
        );
    }

    private static function extractMethod(string $source, string $name): string
    {
        $start = strpos($source, 'function ' . $name . '(');

        if ($start === false) {
            return '';
        }

        $next = strpos($source, "\n    private static function ", $start + 10);

        return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
    }
}
