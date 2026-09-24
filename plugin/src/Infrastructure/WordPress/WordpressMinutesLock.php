<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Access\MinutesLockSetting;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;

final class WordpressMinutesLock
{
    /**
     * @param list<string> $roles
     */
    public static function update(array $roles): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            throw new NotAllowed(Capabilities::MANAGE_ASSOCIATION);
        }

        $change = (new MinutesLockSetting())->change(WordpressAccess::load(), $roles);
        WordpressAccess::save($change->setting());
        WordpressAccess::sync();
        $actor = get_current_user_id();

        if ($actor < 1) {
            return;
        }

        $audit = new WpAuditLog();

        foreach ($change->events() as $event) {
            $audit->record('association_role', $event->roleId(), $event->action(), $actor);
        }
    }
}
