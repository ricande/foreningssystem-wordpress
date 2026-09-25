<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Decision;

use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use InvalidArgumentException;

final class DecisionRegisterQuery
{
    public const PAGE_SIZE = 50;

    public const OPEN = 'open';

    public const DONE = 'done';

    public const ALL = 'all';

    public const UNASSIGNED = 'unassigned';

    public const OVERDUE = 'overdue';

    public function __construct(
        public readonly string $followUp,
        public readonly string $responsible,
        public readonly string $urgency,
        public readonly int $page,
    ) {
    }

    public static function defaults(): self
    {
        return self::normalize(null, null, null, 1);
    }

    public static function normalize(?string $followUp, ?string $responsible, ?string $urgency, int $page): self
    {
        $followUp = in_array($followUp, [self::OPEN, self::DONE, self::ALL], true) ? $followUp : self::OPEN;
        $urgency = $urgency === self::OVERDUE ? self::OVERDUE : self::ALL;

        if ($responsible === self::UNASSIGNED) {
            $responsibleValue = self::UNASSIGNED;
        } elseif (is_string($responsible) && ctype_digit($responsible) && (int) $responsible >= 1) {
            $responsibleValue = (string) (int) $responsible;
        } else {
            $responsibleValue = self::ALL;
        }

        return new self($followUp, $responsibleValue, $urgency, max(1, $page));
    }

    public static function followUpChange(string $value): DecisionFollowUp
    {
        $parsed = DecisionFollowUp::tryFrom($value);

        if ($parsed === null) {
            throw new InvalidArgumentException('Follow-up status is not valid.');
        }

        return $parsed;
    }
}
