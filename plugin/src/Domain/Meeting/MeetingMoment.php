<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

use InvalidArgumentException;

final class MeetingMoment
{
    private function __construct(private readonly string $local)
    {
    }

    public static function fromLocal(string $value): self
    {
        $normalized = strlen($value) === 16 ? $value . ':00' : $value;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $normalized);

        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $normalized) {
            throw new InvalidArgumentException('Meeting time must use YYYY-MM-DD HH:MM.');
        }

        return new self($normalized);
    }

    public function local(): string
    {
        return $this->local;
    }

    public function date(): string
    {
        return substr($this->local, 0, 10);
    }

    public function time(): string
    {
        return substr($this->local, 11, 5);
    }
}
