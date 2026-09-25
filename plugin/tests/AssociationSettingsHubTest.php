<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Infrastructure\WordPress\AssociationSettingsPage;
use Foreningssystem\Infrastructure\WordPress\Plugin;
use PHPUnit\Framework\TestCase;

final class AssociationSettingsHubTest extends TestCase
{
    public function test_settings_hub_exposes_focused_sections(): void
    {
        self::assertSame('foreningsplugin-settings', AssociationSettingsPage::PAGE);
        self::assertSame([
            AssociationSettingsPage::SECTION_PROFILE,
            AssociationSettingsPage::SECTION_BOARD_ROLES,
            AssociationSettingsPage::SECTION_MEETING_TYPES,
            AssociationSettingsPage::SECTION_MINUTES_LOCK,
            AssociationSettingsPage::SECTION_MINUTES_PUBLISH,
            AssociationSettingsPage::SECTION_RETENTION,
        ], AssociationSettingsPage::knownSections());
    }

    public function test_legacy_settings_pages_map_to_hub_sections(): void
    {
        self::assertSame([
            'foreningsplugin-profile' => AssociationSettingsPage::SECTION_PROFILE,
            'foreningsplugin-minutes-lock' => AssociationSettingsPage::SECTION_MINUTES_LOCK,
            'foreningsplugin-minutes-publish' => AssociationSettingsPage::SECTION_MINUTES_PUBLISH,
            'foreningsplugin-retention' => AssociationSettingsPage::SECTION_RETENTION,
        ], AssociationSettingsPage::LEGACY_PAGES);
    }

    public function test_settings_page_source_is_a_hub_not_one_long_form(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/src/Infrastructure/WordPress/AssociationSettingsPage.php'
        );

        self::assertStringContainsString('private static function renderHub', $source);
        self::assertStringContainsString('private static function renderBoardRoles', $source);
        self::assertStringContainsString('private static function renderMeetingTypes', $source);
        self::assertStringContainsString('public static function redirectLegacyPage', $source);
        self::assertStringContainsString('public static function backToHub', $source);
        self::assertStringContainsString("self::settingsUrl(\$entry['section'])", $source);
        self::assertStringContainsString('Run setup guide again', $source);
        self::assertStringNotContainsString('Open association profile', $source);
        self::assertStringNotContainsString('Other settings', $source);
    }

    public function test_menu_registration_hides_legacy_settings_submenus(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/src/Infrastructure/WordPress/Plugin.php'
        );
        $menu = self::extractMethod($source, 'registerAdminMenu');

        self::assertStringContainsString('AssociationSettingsPage::PAGE', $menu);
        self::assertStringContainsString('AssociationSettingsPage::LEGACY_PAGES', $menu);
        self::assertStringContainsString("[AssociationSettingsPage::class, 'redirectLegacyPage']", $menu);
        self::assertDoesNotMatchRegularExpression(
            "/add_submenu_page\(\s*'foreningsplugin',\s*__\('Profile'/",
            $menu
        );
        self::assertDoesNotMatchRegularExpression(
            "/add_submenu_page\(\s*'foreningsplugin',\s*__\('Retention'/",
            $menu
        );
        self::assertDoesNotMatchRegularExpression(
            "/add_submenu_page\(\s*'foreningsplugin',\s*__\('Lock minutes'/",
            $menu
        );
        self::assertDoesNotMatchRegularExpression(
            "/add_submenu_page\(\s*'foreningsplugin',\s*__\('Publish minutes'/",
            $menu
        );
    }

    public function test_focused_settings_pages_redirect_back_into_the_hub(): void
    {
        foreach ([
            'AssociationProfilePage.php' => 'SECTION_PROFILE',
            'MinutesLockPage.php' => 'SECTION_MINUTES_LOCK',
            'MinutesPublishPage.php' => 'SECTION_MINUTES_PUBLISH',
            'RetentionPage.php' => 'SECTION_RETENTION',
        ] as $file => $sectionConstant) {
            $source = (string) file_get_contents(
                dirname(__DIR__) . '/src/Infrastructure/WordPress/' . $file
            );

            self::assertStringContainsString('AssociationSettingsPage::backToHub()', $source);
            self::assertStringContainsString('AssociationSettingsPage::PAGE', $source);
            self::assertStringContainsString('AssociationSettingsPage::' . $sectionConstant, $source);
            self::assertStringNotContainsString("'page' => 'foreningsplugin-profile'", $source);
            self::assertStringNotContainsString("'page' => 'foreningsplugin-minutes-lock'", $source);
            self::assertStringNotContainsString("'page' => 'foreningsplugin-minutes-publish'", $source);
            self::assertStringNotContainsString("'page' => 'foreningsplugin-retention'", $source);
        }

        self::assertSame('0.1.0', Plugin::VERSION);
    }

    private static function extractMethod(string $source, string $name): string
    {
        $start = strpos($source, 'public static function ' . $name . '(');

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
            }

            if ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }
}
