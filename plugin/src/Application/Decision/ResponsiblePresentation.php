<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Decision;

enum ResponsiblePresentation: string
{
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case Unavailable = 'unavailable';
}
