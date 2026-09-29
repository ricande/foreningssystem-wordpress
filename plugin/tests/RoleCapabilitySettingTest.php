<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Domain\Access\AssociationRoleStore;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Domain\Access\RoleCapabilitySetting;
use Foreningssystem\Domain\Access\RoleSynchronizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RoleCapabilitySettingTest extends TestCase
{
    public function test_secretary_cannot_finalize_minutes_until_the_setting_says_so(): void
    {
        $defaults = RoleCapabilitySetting::defaults();

        self::assertNotContains(
            Capabilities::FINALIZE_MINUTES,
            $defaults->capabilitiesFor(RoleBundles::SECRETARY)
        );
        self::assertContains(
            Capabilities::RECORD_MEETING,
            $defaults->capabilitiesFor(RoleBundles::SECRETARY)
        );

        $granted = $defaults->grant(RoleBundles::SECRETARY, Capabilities::FINALIZE_MINUTES);

        self::assertContains(
            Capabilities::FINALIZE_MINUTES,
            $granted->capabilitiesFor(RoleBundles::SECRETARY)
        );
        self::assertNotContains(
            Capabilities::FINALIZE_MINUTES,
            $defaults->capabilitiesFor(RoleBundles::SECRETARY)
        );
    }

    public function test_chair_starts_with_finalize_minutes_and_can_lose_it(): void
    {
        $defaults = RoleCapabilitySetting::defaults();

        self::assertContains(
            Capabilities::FINALIZE_MINUTES,
            $defaults->capabilitiesFor(RoleBundles::CHAIR)
        );

        $revoked = $defaults->revoke(RoleBundles::CHAIR, Capabilities::FINALIZE_MINUTES);

        self::assertNotContains(
            Capabilities::FINALIZE_MINUTES,
            $revoked->capabilitiesFor(RoleBundles::CHAIR)
        );
    }

    public function test_treasurer_does_not_receive_fees_before_that_feature_exists(): void
    {
        $treasurer = RoleCapabilitySetting::defaults()->capabilitiesFor(RoleBundles::TREASURER);

        self::assertContains(Capabilities::EXPORT_MEMBERS, $treasurer);
        self::assertNotContains(Capabilities::MANAGE_FEES, $treasurer);
        self::assertNotContains(Capabilities::FINALIZE_MINUTES, $treasurer);
    }

    public function test_stored_wordpress_capabilities_are_ignored(): void
    {
        $setting = RoleCapabilitySetting::fromArray([
            RoleBundles::SECRETARY => [Capabilities::FINALIZE_MINUTES, 'install_plugins', 'edit_themes', 'manage_options'],
        ]);

        self::assertSame(
            [Capabilities::FINALIZE_MINUTES, Capabilities::ACCESS_ASSOCIATION],
            $setting->capabilitiesFor(RoleBundles::SECRETARY)
        );
    }

    public function test_unknown_capabilities_cannot_be_granted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoleCapabilitySetting::defaults()->grant(RoleBundles::SECRETARY, 'install_plugins');
    }

    public function test_administrator_receives_association_capabilities_only(): void
    {
        $store = new RecordingRoleStore();
        (new RoleSynchronizer($store))->sync(RoleCapabilitySetting::defaults(), []);

        self::assertContains(Capabilities::ACCESS_ASSOCIATION, Capabilities::all());
        self::assertSame(Capabilities::all(), $store->capabilities['administrator']);
        self::assertNotContains('install_plugins', $store->capabilities['administrator']);
        self::assertNotContains('edit_themes', $store->capabilities['administrator']);
        self::assertNotContains('manage_options', $store->capabilities['administrator']);
        self::assertNotContains(
            Capabilities::FINALIZE_MINUTES,
            $store->capabilities[RoleBundles::SECRETARY]
        );
    }

    public function test_a_custom_board_role_keeps_only_minutes_permissions(): void
    {
        $setting = RoleCapabilitySetting::fromArray([
            'custom_material_manager' => [
                Capabilities::FINALIZE_MINUTES,
                Capabilities::MANAGE_ASSOCIATION,
                'install_plugins',
            ],
        ]);

        self::assertSame(
            [Capabilities::FINALIZE_MINUTES, Capabilities::ACCESS_ASSOCIATION],
            $setting->capabilitiesFor('custom_material_manager')
        );
        self::assertTrue($setting->grantsMinutes('custom_material_manager'));
    }

    public function test_a_custom_board_role_cannot_be_granted_other_capabilities(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoleCapabilitySetting::defaults()->grant('custom_material_manager', Capabilities::MANAGE_BOARD);
    }

    public function test_retiring_a_custom_board_role_clears_its_synced_capabilities(): void
    {
        $store = new RecordingRoleStore();
        $setting = RoleCapabilitySetting::defaults()->grant('custom_material_manager', Capabilities::PUBLISH_MINUTES);
        $synchronizer = new RoleSynchronizer($store);
        $synchronizer->sync($setting, ['custom_material_manager' => 'Material manager']);

        self::assertContains(Capabilities::PUBLISH_MINUTES, $store->capabilities['custom_material_manager']);

        $synchronizer->sync(RoleCapabilitySetting::defaults(), [], ['custom_material_manager']);

        self::assertSame([], $store->capabilities['custom_material_manager']);
        self::assertContains(Capabilities::FINALIZE_MINUTES, $store->capabilities[RoleBundles::CHAIR]);
    }
}

final class RecordingRoleStore implements AssociationRoleStore
{
    /** @var array<string, list<string>> */
    public array $capabilities = [];

    public function ensureRole(string $slug, string $displayName): void
    {
    }

    public function replaceManagedCapabilities(string $slug, array $capabilities): void
    {
        $this->capabilities[$slug] = $capabilities;
    }
}
