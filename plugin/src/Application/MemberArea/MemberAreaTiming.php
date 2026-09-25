<?php

declare(strict_types=1);

namespace Foreningssystem\Application\MemberArea;

enum MemberAreaTiming: string
{
    case Current = 'current';
    case Future = 'future';
    case Ended = 'ended';
}
