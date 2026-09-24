<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Access;

use Foreningssystem\Domain\Access\RoleCapabilitySetting;

final class MinutesLockChange
{
    /**
     * @param list<MinutesLockEvent> $events
     */
    public function __construct(
        private readonly RoleCapabilitySetting $setting,
        private readonly array $events,
    ) {
    }

    public function setting(): RoleCapabilitySetting
    {
        return $this->setting;
    }

    /**
     * @return list<MinutesLockEvent>
     */
    public function events(): array
    {
        return $this->events;
    }
}
