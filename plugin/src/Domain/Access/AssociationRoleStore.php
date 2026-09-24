<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Access;

interface AssociationRoleStore
{
    public function ensureRole(string $slug, string $displayName): void;

    /**
     * @param list<string> $capabilities
     */
    public function replaceManagedCapabilities(string $slug, array $capabilities): void;
}
