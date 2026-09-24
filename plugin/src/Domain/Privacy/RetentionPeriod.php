<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Privacy;

use InvalidArgumentException;

final class RetentionPeriod
{
    public const DEFAULT_YEARS = 5;

    public const MIN_YEARS = 1;

    public const MAX_YEARS = 100;

    public function __construct(private readonly int $years)
    {
        if ($this->years < self::MIN_YEARS || $this->years > self::MAX_YEARS) {
            throw new InvalidArgumentException('Retention is a number of years from 1 to 100.');
        }
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_YEARS);
    }

    public function years(): int
    {
        return $this->years;
    }
}
