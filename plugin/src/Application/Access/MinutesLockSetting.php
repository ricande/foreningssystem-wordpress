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
     * @param array<string, int> $extraRoles custom board-role slug => that role's id, used as the audit subject
     */
    public function change(
        RoleCapabilitySetting $current,
        array $roles,
        string $capability = Capabilities::FINALIZE_MINUTES,
        array $extraRoles = [],
    ): MinutesLockChange {
        if (! in_array($capability, [Capabilities::FINALIZE_MINUTES, Capabilities::PUBLISH_MINUTES], true)) {
            throw new InvalidArgumentException('This setting only covers locking and publishing minutes.');
        }

        foreach ($extraRoles as $slug => $auditId) {
            if (! is_string($slug) || ! RoleCapabilitySetting::isCustomBoardSlug($slug) || ! is_int($auditId) || $auditId < 1) {
                throw new InvalidArgumentException('Unknown association role.');
            }
        }

        $allowed = array_merge(RoleBundles::roles(), array_keys($extraRoles));

        foreach ($roles as $role) {
            if (! in_array($role, $allowed, true)) {
                throw new InvalidArgumentException('Unknown association role.');
            }
        }

        $grantAction = $capability === Capabilities::PUBLISH_MINUTES ? 'grant_publish_minutes' : 'grant_finalize_minutes';
        $revokeAction = $capability === Capabilities::PUBLISH_MINUTES ? 'revoke_publish_minutes' : 'revoke_finalize_minutes';
        $next = $current;
        $events = [];

        foreach ($allowed as $role) {
            $shouldHave = in_array($role, $roles, true);
            $hasNow = in_array($capability, $next->capabilitiesFor($role), true);

            if ($shouldHave === $hasNow) {
                continue;
            }

            $next = $shouldHave
                ? $next->grant($role, $capability)
                : $next->revoke($role, $capability);
            $events[] = new MinutesLockEvent(
                in_array($role, RoleBundles::roles(), true) ? RoleBundles::auditId($role) : $extraRoles[$role],
                $shouldHave ? $grantAction : $revokeAction
            );
        }

        foreach ($next->extraSlugs() as $slug) {
            if (! isset($extraRoles[$slug])) {
                $next = $next->withoutExtra($slug);
            }
        }

        return new MinutesLockChange($next, $events);
    }
}
