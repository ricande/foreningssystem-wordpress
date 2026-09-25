<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Decision;

enum AgendaPresentation: string
{
    case None = 'none';
    case Item = 'item';
    case Unavailable = 'unavailable';
}
