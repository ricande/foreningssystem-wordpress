<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Account;

/**
 * `assoc-member-` followed by 16 random hex characters (64 bits).
 *
 * The prefix tells officers which accounts the plugin created. The suffix cannot be
 * derived from the Person id, so knowing one login does not reveal the others (ADR-0033).
 */
final class RandomMemberLogins implements MemberLogins
{
    public const PREFIX = 'assoc-member-';

    public function next(): string
    {
        return self::PREFIX . bin2hex(random_bytes(8));
    }
}
