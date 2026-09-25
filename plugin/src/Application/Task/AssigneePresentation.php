<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Task;

enum AssigneePresentation: string
{
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case Unavailable = 'unavailable';
}
