<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

enum Presence: string
{
    case Present = 'present';
    case Absent = 'absent';
    case CoOpted = 'co_opted';
}
