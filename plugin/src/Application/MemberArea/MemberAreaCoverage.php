<?php

declare(strict_types=1);

namespace Foreningssystem\Application\MemberArea;

use Foreningssystem\Domain\Membership\MembershipKind;

final class MemberAreaCoverage
{
    public function __construct(
        public readonly string $number,
        public readonly MembershipKind $kind,
        public readonly string $from,
        public readonly ?string $to,
        public readonly MemberAreaTiming $timing,
    ) {
    }
}
