<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

enum MembershipStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Dormant = 'dormant';
    case Ended = 'ended';
}
