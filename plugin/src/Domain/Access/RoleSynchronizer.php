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
     */
    public function sync(RoleCapabilitySetting $setting, array $displayNames): void
    {
        foreach (RoleBundles::roles() as $role) {
            $this->roles->ensureRole($role, $displayNames[$role] ?? $role);
            $this->roles->replaceManagedCapabilities($role, $setting->capabilitiesFor($role));
        }

        $this->roles->replaceManagedCapabilities('administrator', Capabilities::all());
    }
}
