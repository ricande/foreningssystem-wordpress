<?php

declare(strict_types=1);

namespace Foreningssystem\Application\MemberArea;

enum MemberAreaState: string
{
    case LoggedOut = 'logged_out';
    case Unlinked = 'unlinked';
    case Unavailable = 'unavailable';
    case Linked = 'linked';
}
