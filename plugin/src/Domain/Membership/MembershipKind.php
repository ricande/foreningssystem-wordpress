<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

use InvalidArgumentException;

enum MembershipKind: string
{
    case Ordinary = 'ordinary';
    case Youth = 'youth';
    case Family = 'family';
    case Company = 'company';

    public static function fromSlug(string $slug): self
    {
        $kind = self::tryFrom(strtolower(trim($slug)));

        if (! $kind instanceof self) {
            throw new InvalidArgumentException('Unknown membership kind.');
        }

        return $kind;
    }

    public static function knownSlug(string $slug): bool
    {
        return self::tryFrom(strtolower(trim($slug))) instanceof self;
    }
}
