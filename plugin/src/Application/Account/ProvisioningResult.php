<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Account;

final class ProvisioningResult
{
    public function __construct(
        public readonly AccountOutcome $outcome,
        public readonly ?int $personId,
        public readonly ?int $wordpressUserId = null,
    ) {
    }
}
