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
}
