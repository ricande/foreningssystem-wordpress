<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Access\AssociationRoleStore;

final class WordpressAssociationRoleStore implements AssociationRoleStore
{
    public function ensureRole(string $slug, string $displayName): void
    {
        if (get_role($slug) instanceof \WP_Role) {
            return;
        }

        add_role($slug, $displayName, []);
    }

    public function replaceManagedCapabilities(string $slug, array $capabilities): void
    {
        $role = get_role($slug);

        if (! $role instanceof \WP_Role) {
            return;
        }

        foreach (Capabilities::all() as $capability) {
            if (in_array($capability, $capabilities, true)) {
                $role->add_cap($capability);

                continue;
            }

            $role->remove_cap($capability);
        }
    }
}
