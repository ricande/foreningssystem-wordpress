<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

enum ParticipantRole: string
{
    case Member = 'member';
    case Contact = 'contact';

    public function countsAsMember(): bool
    {
        return $this === self::Member;
    }
}
