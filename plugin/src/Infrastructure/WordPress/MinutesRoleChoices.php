<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Settings\BuiltinStructure;
use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Domain\Access\RoleCapabilitySetting;
use Foreningssystem\Domain\Board\BoardRole;

final class MinutesRoleChoices
{
    /**
     * Association roles, then custom board roles. Built-in board slugs stay out of this list.
     * Chair, secretary, treasurer, and board member are already the four association roles.
     * A custom row whose name repeats one of those labels stays out too.
     *
     * @param list<BoardRole> $boardRoles
     * @return array<string, string>
     */
    public static function labels(array $boardRoles): array
    {
        $labels = [
            RoleBundles::SECRETARY => __('Secretary', 'foreningsplugin'),
            RoleBundles::CHAIR => __('Chair', 'foreningsplugin'),
            RoleBundles::TREASURER => __('Treasurer', 'foreningsplugin'),
            RoleBundles::BOARD_MEMBER => __('Board member', 'foreningsplugin'),
        ];

        foreach (self::custom($boardRoles) as $role) {
            $labels[$role->slug()] = $role->name();
        }

        return $labels;
    }

    /**
     * @param list<BoardRole> $boardRoles
     * @return array<string, int>
     */
    public static function extraRoles(array $boardRoles): array
    {
        $extra = [];

        foreach (self::custom($boardRoles) as $role) {
            $id = $role->id();

            if ($id === null || $id < 1 || ! RoleCapabilitySetting::isCustomBoardSlug($role->slug())) {
                continue;
            }

            $extra[$role->slug()] = $id;
        }

        return $extra;
    }

    /**
     * @param list<BoardRole> $boardRoles
     * @return list<BoardRole>
     */
    private static function custom(array $boardRoles): array
    {
        $custom = [];

        foreach ($boardRoles as $role) {
            if (BuiltinStructure::isBoard($role->slug()) || BuiltinStructure::matchesAssociationRoleName($role->name())) {
                continue;
            }

            $custom[] = $role;
        }

        usort(
            $custom,
            static function (BoardRole $left, BoardRole $right): int {
                $byOrder = $left->sortOrder() <=> $right->sortOrder();

                return $byOrder !== 0 ? $byOrder : (($left->id() ?? 0) <=> ($right->id() ?? 0));
            }
        );

        return $custom;
    }
}
