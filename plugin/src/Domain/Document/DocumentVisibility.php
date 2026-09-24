<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Document;

enum DocumentVisibility: string
{
    case Board = 'board';
    case Member = 'member';
    case Administrator = 'administrator';
    case Public = 'public';
}
