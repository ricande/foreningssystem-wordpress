<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Document;

enum DocumentVisibility: string
{
    case Board = 'board';
    case Public = 'public';
}
