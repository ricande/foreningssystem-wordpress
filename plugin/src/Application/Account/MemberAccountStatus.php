<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Account;

final class MemberAccountStatus
{
    public function __construct(
        public readonly AccountOutcome $outcome,
        public readonly string $personEmail,
        public readonly ?string $accountName,
        public readonly ?string $accountEmail,
        public readonly ?int $wordpressUserId,
        public readonly bool $emailMismatch,
    ) {
    }
}
