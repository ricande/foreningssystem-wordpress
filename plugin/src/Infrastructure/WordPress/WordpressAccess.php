<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Domain\Access\RoleCapabilitySetting;
use Foreningssystem\Domain\Access\RoleSynchronizer;

final class WordpressAccess
{
    public const OPTION = 'assoc_role_bundles';

    public static function sync(): void
    {
        $synchronizer = new RoleSynchronizer(new WordpressAssociationRoleStore());
        $synchronizer->sync(self::load(), self::displayNames());
    }

    public static function load(): RoleCapabilitySetting
    {
        $stored = get_option(self::OPTION, null);

        if (! is_array($stored)) {
            $setting = RoleCapabilitySetting::defaults();
            update_option(self::OPTION, $setting->toArray());

            return $setting;
        }

        return RoleCapabilitySetting::fromArray($stored);
    }

    public static function save(RoleCapabilitySetting $setting): void
    {
        update_option(self::OPTION, $setting->toArray());
    }

    /**
     * @return array<string, string>
     */
    private static function displayNames(): array
    {
        return [
            RoleBundles::SECRETARY => __('Sekreterare', 'foreningsplugin'),
            RoleBundles::CHAIR => __('Ordförande', 'foreningsplugin'),
            RoleBundles::TREASURER => __('Kassör', 'foreningsplugin'),
            RoleBundles::BOARD_MEMBER => __('Styrelseledamot', 'foreningsplugin'),
        ];
    }
}
