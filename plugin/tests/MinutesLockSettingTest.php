<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Access\MinutesLockSetting;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Domain\Access\RoleCapabilitySetting;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MinutesLockSettingTest extends TestCase
{
    public function test_the_chair_starts_able_to_lock_minutes_and_another_role_can_receive_it(): void
    {
        $change = (new MinutesLockSetting())->change(RoleCapabilitySetting::defaults(), [
            RoleBundles::CHAIR,
            RoleBundles::SECRETARY,
        ]);
        $secretary = $change->setting()->capabilitiesFor(RoleBundles::SECRETARY);
        $chair = $change->setting()->capabilitiesFor(RoleBundles::CHAIR);

        self::assertContains(Capabilities::FINALIZE_MINUTES, $secretary);
        self::assertContains(Capabilities::RECORD_MEETING, $secretary);
        self::assertNotContains(Capabilities::PUBLISH_MINUTES, $secretary);
        self::assertNotContains('install_plugins', $secretary);
        self::assertContains(Capabilities::FINALIZE_MINUTES, $chair);
        self::assertContains(Capabilities::PUBLISH_MINUTES, $chair);
        self::assertContains(Capabilities::MANAGE_BOARD, $chair);
        self::assertCount(1, $change->events());
        self::assertSame(RoleBundles::auditId(RoleBundles::SECRETARY), $change->events()[0]->roleId());
        self::assertSame('grant_finalize_minutes', $change->events()[0]->action());
        self::assertStringNotContainsString('@', $change->events()[0]->action());
    }

    public function test_removing_the_chair_keeps_the_other_chair_capabilities(): void
    {
        $change = (new MinutesLockSetting())->change(RoleCapabilitySetting::defaults(), []);
        $chair = $change->setting()->capabilitiesFor(RoleBundles::CHAIR);

        self::assertNotContains(Capabilities::FINALIZE_MINUTES, $chair);
        self::assertContains(Capabilities::PUBLISH_MINUTES, $chair);
        self::assertContains(Capabilities::MANAGE_BOARD, $chair);
        self::assertSame('revoke_finalize_minutes', $change->events()[0]->action());
        self::assertSame(RoleBundles::auditId(RoleBundles::CHAIR), $change->events()[0]->roleId());
    }

    public function test_an_unchanged_setting_records_nothing(): void
    {
        $change = (new MinutesLockSetting())->change(RoleCapabilitySetting::defaults(), [RoleBundles::CHAIR]);

        self::assertSame([], $change->events());
        self::assertContains(
            Capabilities::FINALIZE_MINUTES,
            $change->setting()->capabilitiesFor(RoleBundles::CHAIR)
        );
    }

    public function test_publishing_can_be_given_to_another_role_without_changing_who_may_lock(): void
    {
        $change = (new MinutesLockSetting())->change(RoleCapabilitySetting::defaults(), [
            RoleBundles::CHAIR,
            RoleBundles::SECRETARY,
        ], Capabilities::PUBLISH_MINUTES);
        $secretary = $change->setting()->capabilitiesFor(RoleBundles::SECRETARY);
        $chair = $change->setting()->capabilitiesFor(RoleBundles::CHAIR);

        self::assertContains(Capabilities::PUBLISH_MINUTES, $secretary);
        self::assertNotContains(Capabilities::FINALIZE_MINUTES, $secretary);
        self::assertContains(Capabilities::RECORD_MEETING, $secretary);
        self::assertContains(Capabilities::PUBLISH_MINUTES, $chair);
        self::assertContains(Capabilities::FINALIZE_MINUTES, $chair);
        self::assertCount(1, $change->events());
        self::assertSame('grant_publish_minutes', $change->events()[0]->action());
        self::assertSame(RoleBundles::auditId(RoleBundles::SECRETARY), $change->events()[0]->roleId());
    }

    public function test_removing_publication_keeps_the_lock(): void
    {
        $change = (new MinutesLockSetting())->change(RoleCapabilitySetting::defaults(), [], Capabilities::PUBLISH_MINUTES);
        $chair = $change->setting()->capabilitiesFor(RoleBundles::CHAIR);

        self::assertNotContains(Capabilities::PUBLISH_MINUTES, $chair);
        self::assertContains(Capabilities::FINALIZE_MINUTES, $chair);
        self::assertSame('revoke_publish_minutes', $change->events()[0]->action());
    }

    public function test_an_unknown_role_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MinutesLockSetting())->change(RoleCapabilitySetting::defaults(), ['administrator']);
    }

    public function test_secretary_finalize_with_empty_publish_selection_is_valid(): void
    {
        $lock = (new MinutesLockSetting())->change(
            RoleCapabilitySetting::defaults(),
            [RoleBundles::SECRETARY]
        );
        $publish = (new MinutesLockSetting())->change(
            $lock->setting(),
            [],
            Capabilities::PUBLISH_MINUTES
        );

        self::assertContains(
            Capabilities::FINALIZE_MINUTES,
            $publish->setting()->capabilitiesFor(RoleBundles::SECRETARY)
        );
        self::assertNotContains(
            Capabilities::FINALIZE_MINUTES,
            $publish->setting()->capabilitiesFor(RoleBundles::CHAIR)
        );
        self::assertNotContains(
            Capabilities::PUBLISH_MINUTES,
            $publish->setting()->capabilitiesFor(RoleBundles::CHAIR)
        );
        self::assertNotContains(
            Capabilities::PUBLISH_MINUTES,
            $publish->setting()->capabilitiesFor(RoleBundles::SECRETARY)
        );
    }

    public function test_a_custom_board_role_can_finalize_and_publish_minutes(): void
    {
        $extra = ['custom_material_manager' => 42];
        $lock = (new MinutesLockSetting())->change(
            RoleCapabilitySetting::defaults(),
            [RoleBundles::CHAIR, 'custom_material_manager'],
            Capabilities::FINALIZE_MINUTES,
            $extra
        );
        $publish = (new MinutesLockSetting())->change(
            $lock->setting(),
            [RoleBundles::CHAIR, 'custom_material_manager'],
            Capabilities::PUBLISH_MINUTES,
            $extra
        );
        $custom = $publish->setting()->capabilitiesFor('custom_material_manager');

        self::assertContains(Capabilities::FINALIZE_MINUTES, $custom);
        self::assertContains(Capabilities::PUBLISH_MINUTES, $custom);
        self::assertNotContains(Capabilities::MANAGE_BOARD, $custom);
        self::assertNotContains(Capabilities::MANAGE_ASSOCIATION, $custom);
        self::assertContains(Capabilities::FINALIZE_MINUTES, $publish->setting()->capabilitiesFor(RoleBundles::CHAIR));
        self::assertSame(42, $lock->events()[0]->roleId());
        self::assertSame('grant_finalize_minutes', $lock->events()[0]->action());
        self::assertSame(42, $publish->events()[0]->roleId());
        self::assertSame('grant_publish_minutes', $publish->events()[0]->action());
    }

    public function test_a_custom_board_role_outside_the_catalog_cannot_receive_minutes_permission(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MinutesLockSetting())->change(
            RoleCapabilitySetting::defaults(),
            ['custom_material_manager']
        );
    }

    public function test_a_crafted_role_cannot_enter_the_minutes_setting(): void
    {
        foreach (['administrator', 'chair', 'assoc_chair_extra'] as $role) {
            try {
                (new MinutesLockSetting())->change(
                    RoleCapabilitySetting::defaults(),
                    [$role],
                    Capabilities::FINALIZE_MINUTES,
                    ['custom_material_manager' => 42]
                );
                self::fail('The role ' . $role . ' was accepted.');
            } catch (InvalidArgumentException) {
                self::assertNotContains(
                    Capabilities::FINALIZE_MINUTES,
                    RoleCapabilitySetting::defaults()->capabilitiesFor($role)
                );
            }
        }

        try {
            (new MinutesLockSetting())->change(
                RoleCapabilitySetting::defaults(),
                [RoleBundles::CHAIR],
                Capabilities::FINALIZE_MINUTES,
                ['administrator' => 1]
            );
            self::fail('An administrator bundle was accepted as a custom board role.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function test_clearing_a_custom_role_drops_it_from_the_minutes_setting(): void
    {
        $extra = ['custom_material_manager' => 42];
        $granted = (new MinutesLockSetting())->change(
            RoleCapabilitySetting::defaults(),
            [RoleBundles::CHAIR, 'custom_material_manager'],
            Capabilities::FINALIZE_MINUTES,
            $extra
        );
        $cleared = (new MinutesLockSetting())->change(
            $granted->setting(),
            [RoleBundles::CHAIR],
            Capabilities::FINALIZE_MINUTES,
            $extra
        );

        self::assertFalse($cleared->setting()->grantsMinutes('custom_material_manager'));
        self::assertNotContains('custom_material_manager', $cleared->setting()->extraSlugs());
        self::assertContains(
            Capabilities::FINALIZE_MINUTES,
            $cleared->setting()->capabilitiesFor(RoleBundles::CHAIR)
        );
    }
}
