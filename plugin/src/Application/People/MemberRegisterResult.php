<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

final class MemberRegisterResult
{
    /**
     * @param list<MemberRegisterIssue> $issues
     */
    public function __construct(
        public readonly int $imported,
        public readonly int $duplicates,
        public readonly int $failed,
        public readonly array $issues,
    ) {
    }
}
