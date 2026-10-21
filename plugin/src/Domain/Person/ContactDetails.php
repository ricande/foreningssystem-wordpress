<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Person;

use InvalidArgumentException;

/**
 * A person's postal address and phone number. Every part is optional and free text,
 * so an address abroad fits. See ADR-0035.
 */
final class ContactDetails
{
    public const STREET_MAX = 190;

    public const POSTAL_CODE_MAX = 20;

    public const CITY_MAX = 100;

    public const PHONE_MAX = 40;

    private readonly string $streetAddress;

    private readonly string $postalCode;

    private readonly string $city;

    private readonly string $phone;

    public function __construct(
        string $streetAddress = '',
        string $postalCode = '',
        string $city = '',
        string $phone = '',
    ) {
        $this->streetAddress = self::part($streetAddress, self::STREET_MAX, 'Street address is too long.');
        $this->postalCode = self::part($postalCode, self::POSTAL_CODE_MAX, 'Postal code is too long.');
        $this->city = self::part($city, self::CITY_MAX, 'City is too long.');
        $this->phone = self::part($phone, self::PHONE_MAX, 'Phone number is too long.');

        if ($this->phone !== '' && preg_match('/\A\+?[0-9][0-9 ()\-]*\z/', $this->phone) !== 1) {
            throw new InvalidArgumentException('Phone number is not valid.');
        }
    }

    public function streetAddress(): string
    {
        return $this->streetAddress;
    }

    public function postalCode(): string
    {
        return $this->postalCode;
    }

    public function city(): string
    {
        return $this->city;
    }

    public function phone(): string
    {
        return $this->phone;
    }

    public function isEmpty(): bool
    {
        return $this->streetAddress === '' && $this->postalCode === '' && $this->city === '' && $this->phone === '';
    }

    public function sameAddress(self $other): bool
    {
        return $this->streetAddress === $other->streetAddress
            && $this->postalCode === $other->postalCode
            && $this->city === $other->city;
    }

    private static function part(string $value, int $max, string $tooLong): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if (mb_strlen($value, 'UTF-8') > $max) {
            throw new InvalidArgumentException($tooLong);
        }

        return $value;
    }
}
