<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Board;

/**
 * Presentation mode only. Does not change BoardService rules.
 */
final class BoardWizardMode
{
    public const GET_STARTED = 'get_started';

    public const CHANGE = 'change';

    /**
     * Empty or nearly empty board: no current holders and no upcoming changes.
     *
     * @param list<BoardSeat> $seats
     */
    public static function fromSeats(array $seats): string
    {
        foreach ($seats as $seat) {
            if ($seat->state() === 'current' || $seat->state() === 'upcoming') {
                return self::CHANGE;
            }
        }

        return self::GET_STARTED;
    }
}
