<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

enum PublicationVisibility: string
{
    case Board = 'board';
    case Public = 'public';
}
