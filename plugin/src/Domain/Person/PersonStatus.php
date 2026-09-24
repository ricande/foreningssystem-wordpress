<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Person;

enum PersonStatus: string
{
    case Known = 'known';
    case Deceased = 'deceased';
}
