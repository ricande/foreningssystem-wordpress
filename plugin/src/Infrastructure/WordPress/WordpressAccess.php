<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Settings\BuiltinStructure;
use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Domain\Access\RoleCapabilitySetting;
use Foreningssystem\Domain\Access\RoleSynchronizer;

final class WordpressAccess
{
    public const OPTION = 'assoc_role_bundles';

    /**
     * @param list<string> $retiredSlugs
     */
    public static function sync(array $retiredSlugs = []): void
    {
        $synchronizer = new RoleSynchronizer(new WordpressAssociationRoleStore());
        $synchronizer->sync(self::load(), self::displayNames(), $retiredSlugs);
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
        $names = [
            RoleBundles::SECRETARY => __('Secretary', 'foreningsplugin'),
            RoleBundles::CHAIR => __('Chair', 'foreningsplugin'),
            RoleBundles::TREASURER => __('Treasurer', 'foreningsplugin'),
            RoleBundles::BOARD_MEMBER => __('Board member', 'foreningsplugin'),
        ];

        foreach ((new WpdbBoardRoleRepository())->all() as $role) {
            if (! BuiltinStructure::isBoard($role->slug())) {
                $names[$role->slug()] = $role->name();
            }
        }

        return $names;
    }
}
