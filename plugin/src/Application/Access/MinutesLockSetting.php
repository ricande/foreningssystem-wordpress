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
    public function change(RoleCapabilitySetting $current, array $roles, string $capability = Capabilities::FINALIZE_MINUTES): MinutesLockChange
    {
        if (! in_array($capability, [Capabilities::FINALIZE_MINUTES, Capabilities::PUBLISH_MINUTES], true)) {
            throw new InvalidArgumentException('This setting only covers locking and publishing minutes.');
        }

        foreach ($roles as $role) {
            if (! in_array($role, RoleBundles::roles(), true)) {
                throw new InvalidArgumentException('Unknown association role.');
            }
        }

        $grantAction = $capability === Capabilities::PUBLISH_MINUTES ? 'grant_publish_minutes' : 'grant_finalize_minutes';
        $revokeAction = $capability === Capabilities::PUBLISH_MINUTES ? 'revoke_publish_minutes' : 'revoke_finalize_minutes';
        $next = $current;
        $events = [];

        foreach (RoleBundles::roles() as $role) {
            $shouldHave = in_array($role, $roles, true);
            $hasNow = in_array($capability, $next->capabilitiesFor($role), true);

            if ($shouldHave === $hasNow) {
                continue;
            }

            $next = $shouldHave
                ? $next->grant($role, $capability)
                : $next->revoke($role, $capability);
            $events[] = new MinutesLockEvent(
                RoleBundles::auditId($role),
                $shouldHave ? $grantAction : $revokeAction
            );
        }

        return new MinutesLockChange($next, $events);
    }
}
