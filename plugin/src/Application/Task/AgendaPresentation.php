<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Task;

enum AgendaPresentation: string
{
    case None = 'none';
    case Item = 'item';
    case Unavailable = 'unavailable';
}
