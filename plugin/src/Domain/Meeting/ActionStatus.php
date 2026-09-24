<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

enum ActionStatus: string
{
    case Open = 'open';
    case Done = 'done';
}
