<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

enum RevisionState: string
{
    case Draft = 'draft';
    case UnderAdjustment = 'under_adjustment';
    case Finalized = 'finalized';
}
