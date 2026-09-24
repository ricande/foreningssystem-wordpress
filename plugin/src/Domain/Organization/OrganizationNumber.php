<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Organization;

use InvalidArgumentException;

final class OrganizationNumber
{
    private function __construct(private readonly string $canonical)
    {
    }

    public static function parse(string $raw): self
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if (strlen($digits) !== 10 || ! self::checksum($digits)) {
            throw new InvalidArgumentException('The organization number is not valid.');
        }

        return new self(substr($digits, 0, 6) . '-' . substr($digits, 6));
    }

    public function canonical(): string
    {
        return $this->canonical;
    }

    private static function checksum(string $ten): bool
    {
        $sum = 0;

        for ($index = 0; $index < 10; $index++) {
            $digit = (int) $ten[$index];

            if ($index % 2 === 0) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
