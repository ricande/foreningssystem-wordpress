<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Account;

/**
 * Chooses the login name for a member account the plugin creates (ADR-0033).
 */
interface MemberLogins
{
    public function next(): string;
}
