<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

use InvalidArgumentException;

final class Age
{
    public static function years(AssociationDate $birth, AssociationDate $on): int
    {
        if ($birth->isAfter($on)) {
            throw new InvalidArgumentException('Birth date cannot be in the future.');
        }

        $years = (int) substr($on->iso(), 0, 4) - (int) substr($birth->iso(), 0, 4);
        $birthdayPassed = substr($on->iso(), 5) >= substr($birth->iso(), 5);

        return $birthdayPassed ? $years : $years - 1;
    }
}
