<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Access\MinutesLockSetting;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;

final class WordpressMinutesPublish
{
    /**
     * @param list<string> $roles
     */
    public static function update(array $roles): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            throw new NotAllowed(Capabilities::MANAGE_ASSOCIATION);
        }

        $before = WordpressAccess::load();
        $change = (new MinutesLockSetting())->change(
            $before,
            $roles,
            Capabilities::PUBLISH_MINUTES,
            MinutesRoleChoices::extraRoles((new WpdbBoardRoleRepository())->all())
        );
        WordpressAccess::save($change->setting());
        WordpressAccess::sync(array_values(array_diff($before->extraSlugs(), $change->setting()->extraSlugs())));
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
