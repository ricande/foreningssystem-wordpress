<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

enum MeetingStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Held = 'held';
}
