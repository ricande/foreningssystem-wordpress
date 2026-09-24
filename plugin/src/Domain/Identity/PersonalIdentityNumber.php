<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Identity;

use Foreningssystem\Domain\Membership\AssociationDate;
use InvalidArgumentException;

final class PersonalIdentityNumber
{
    private function __construct(private readonly string $canonical)
    {
    }

    public static function parse(string $raw, AssociationDate $today): self
    {
        $compact = strtoupper(preg_replace('/\s+/', '', trim($raw)) ?? '');
        $separator = str_contains($compact, '+') ? '+' : '-';
        $digits = preg_replace('/\D/', '', $compact) ?? '';

        if (strlen($digits) === 12) {
            $century = substr($digits, 0, 4);
            $ten = substr($digits, 2);
        } elseif (strlen($digits) === 10) {
            $century = self::century(substr($digits, 0, 6), $separator, $today);
            $ten = $digits;
        } else {
            throw new InvalidArgumentException('The personal identity number is not valid.');
        }

        if (! self::checksum($ten) || ! self::calendar($century . substr($ten, 2, 4))) {
            throw new InvalidArgumentException('The personal identity number is not valid.');
        }

        return new self($century . substr($ten, 2, 4) . '-' . substr($ten, 6, 4));
    }

    public function canonical(): string
    {
        return $this->canonical;
    }

    public function masked(): string
    {
        return substr($this->canonical, 0, 4) . '••••-' . substr($this->canonical, 9, 4);
    }

    public function civilBirthDate(): AssociationDate
    {
        $day = (int) substr($this->canonical, 6, 2);

        if ($day > 60) {
            $day -= 60;
        }

        return AssociationDate::fromIso(sprintf('%s-%s-%02d', substr($this->canonical, 0, 4), substr($this->canonical, 4, 2), $day));
    }

    private static function century(string $six, string $separator, AssociationDate $today): string
    {
        $year = (int) substr($six, 0, 2);
        $recent = 2000 + $year;
        $date = sprintf('%04d-%s-%s', $recent, substr($six, 2, 2), self::civilDay(substr($six, 4, 2)));

        if ($separator === '+') {
            return (string) ($recent - 100);
        }

        if ($date > $today->iso()) {
            return (string) ($recent - 100);
        }

        return (string) $recent;
    }

    private static function calendar(string $eight): bool
    {
        $month = (int) substr($eight, 4, 2);
        $day = (int) substr($eight, 6, 2);
        $civilDay = $day > 60 ? $day - 60 : $day;
        $year = (int) substr($eight, 0, 4);

        return $month >= 1 && $month <= 12 && checkdate($month, $civilDay, $year);
    }

    private static function civilDay(string $day): string
    {
        $value = (int) $day;

        return sprintf('%02d', $value > 60 ? $value - 60 : $value);
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
