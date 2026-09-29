<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Access;

final class RoleSynchronizer
{
    public function __construct(private readonly AssociationRoleStore $roles)
    {
    }

    /**
     * @param array<string, string> $displayNames
     * @param list<string> $retiredSlugs custom board roles that no longer hold a minutes permission
     */
    public function sync(RoleCapabilitySetting $setting, array $displayNames, array $retiredSlugs = []): void
    {
        foreach (array_keys($setting->toArray()) as $role) {
            $this->roles->ensureRole($role, $displayNames[$role] ?? $role);
            $this->roles->replaceManagedCapabilities($role, $setting->capabilitiesFor($role));
        }

        foreach ($retiredSlugs as $slug) {
            if (
                ! is_string($slug)
                || in_array($slug, RoleBundles::roles(), true)
                || ! RoleCapabilitySetting::isCustomBoardSlug($slug)
                || in_array($slug, $setting->extraSlugs(), true)
            ) {
                continue;
            }

            $this->roles->replaceManagedCapabilities($slug, []);
        }

        $this->roles->replaceManagedCapabilities('administrator', Capabilities::all());
    }
}
