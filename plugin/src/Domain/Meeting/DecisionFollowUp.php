<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

enum DecisionFollowUp: string
{
    case Open = 'open';
    case Done = 'done';
}
