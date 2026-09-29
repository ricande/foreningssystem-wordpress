<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

enum MeetingDuty: string
{
    case None = 'none';
    case Chair = 'chair';
    case Secretary = 'secretary';
    case Adjuster = 'adjuster';
}
