<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

enum MeetingDuty: string
{
    case None = 'none';
    case Chair = 'chair';
    case Adjuster = 'adjuster';
}
