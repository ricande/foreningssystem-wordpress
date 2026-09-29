<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

final class MemberRegisterIssue
{
    public function __construct(
        public readonly int $line,
        public readonly string $kind,
        public readonly string $code,
    ) {
    }
}
