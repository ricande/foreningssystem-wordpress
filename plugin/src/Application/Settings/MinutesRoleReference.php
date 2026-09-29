<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Settings;

/**
 * Whether a board-role slug is selected for finalizing or publishing minutes.
 *
 * The selection is a system permission. It is not an appointment of who adjusts one meeting.
 */
interface MinutesRoleReference
{
    public function references(string $slug): bool;
}
