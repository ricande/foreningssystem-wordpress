<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

use InvalidArgumentException;

final class AssociationDate
{
    private function __construct(private readonly string $iso)
    {
    }

    public static function fromIso(string $iso): self
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);

        if ($parsed === false || $parsed->format('Y-m-d') !== $iso) {
            throw new InvalidArgumentException('Date must use YYYY-MM-DD.');
        }

        return new self($iso);
    }

    public function iso(): string
    {
        return $this->iso;
    }

    public function isAfter(self $other): bool
    {
        return $this->iso > $other->iso;
    }

    public function isBefore(self $other): bool
    {
        return $this->iso < $other->iso;
    }

    public function previousDay(): self
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->iso);

        if ($parsed === false) {
            throw new InvalidArgumentException('Date must use YYYY-MM-DD.');
        }

        return new self($parsed->modify('-1 day')->format('Y-m-d'));
    }
}
