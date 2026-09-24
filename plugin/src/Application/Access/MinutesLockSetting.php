<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Access;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Domain\Access\RoleCapabilitySetting;
use InvalidArgumentException;

final class MinutesLockSetting
{
    /**
     * @param list<string> $roles
     */
    public function change(RoleCapabilitySetting $current, array $roles): MinutesLockChange
    {
        foreach ($roles as $role) {
            if (! in_array($role, RoleBundles::roles(), true)) {
                throw new InvalidArgumentException('Unknown association role.');
            }
        }

        $next = $current;
        $events = [];

        foreach (RoleBundles::roles() as $role) {
            $shouldLock = in_array($role, $roles, true);
            $locksNow = in_array(Capabilities::FINALIZE_MINUTES, $next->capabilitiesFor($role), true);

            if ($shouldLock === $locksNow) {
                continue;
            }

            $next = $shouldLock
                ? $next->grant($role, Capabilities::FINALIZE_MINUTES)
                : $next->revoke($role, Capabilities::FINALIZE_MINUTES);
            $events[] = new MinutesLockEvent(
                RoleBundles::auditId($role),
                $shouldLock ? 'grant_finalize_minutes' : 'revoke_finalize_minutes'
            );
        }

        return new MinutesLockChange($next, $events);
    }
}
