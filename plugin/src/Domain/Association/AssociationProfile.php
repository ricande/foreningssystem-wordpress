<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Association;

use Foreningssystem\Domain\Membership\AssociationDate;
use InvalidArgumentException;

final class AssociationProfile
{
    public const NAME_MAX = 200;

    public const ORGANIZATION_NUMBER_MAX = 32;

    public const ADDRESS_MAX = 500;

    public const EMAIL_MAX = 200;

    public const PHONE_MAX = 40;

    public const LANGUAGE_SWEDISH = 'sv';

    public const LANGUAGE_ENGLISH = 'en';

    public function __construct(
        private readonly string $name,
        private readonly string $organizationNumber,
        private readonly string $address,
        private readonly string $email,
        private readonly string $phone,
        private readonly string $language,
        private readonly ?int $logoAttachmentId,
        private readonly int $membershipYearStartMonth,
        private readonly int $membershipYearStartDay,
    ) {
        $this->assertPlain($this->name, self::NAME_MAX, false, 'Association name');
        $this->assertOrganizationNumber();
        $this->assertPlain($this->address, self::ADDRESS_MAX, true, 'Address');
        $this->assertEmail();
        $this->assertPhone();
        $this->assertLanguage();
        $this->assertLogo();
        $this->assertStartDay();
    }

    public static function empty(): self
    {
        return new self('', '', '', '', '', self::LANGUAGE_SWEDISH, null, 1, 1);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function organizationNumber(): string
    {
        return $this->organizationNumber;
    }

    public function address(): string
    {
        return $this->address;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function phone(): string
    {
        return $this->phone;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function logoAttachmentId(): ?int
    {
        return $this->logoAttachmentId;
    }

    public function membershipYearStartMonth(): int
    {
        return $this->membershipYearStartMonth;
    }

    public function membershipYearStartDay(): int
    {
        return $this->membershipYearStartDay;
    }

    public function membershipYearStart(): string
    {
        return sprintf('%02d-%02d', $this->membershipYearStartMonth, $this->membershipYearStartDay);
    }

    public function membershipYear(AssociationDate $day): MembershipYear
    {
        $year = (int) substr($day->iso(), 0, 4);
        $startYear = substr($day->iso(), 5) >= $this->membershipYearStart() ? $year : $year - 1;
        $startedOn = AssociationDate::fromIso(sprintf('%04d-%s', $startYear, $this->membershipYearStart()));
        $nextStart = AssociationDate::fromIso(sprintf('%04d-%s', $startYear + 1, $this->membershipYearStart()));

        return new MembershipYear($startedOn, $nextStart->previousDay());
    }

    private function assertPlain(string $value, int $max, bool $allowNewlines, string $label): void
    {
        $pattern = $allowNewlines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';

        if (preg_match($pattern, $value) === 1 || mb_strlen($value) > $max) {
            throw new InvalidArgumentException($label . ' is too long or contains a control character.');
        }
    }

    private function assertOrganizationNumber(): void
    {
        if ($this->organizationNumber === '') {
            return;
        }

        if (
            mb_strlen($this->organizationNumber) > self::ORGANIZATION_NUMBER_MAX
            || preg_match('/^[0-9A-Za-z][0-9A-Za-z -]*$/', $this->organizationNumber) !== 1
        ) {
            throw new InvalidArgumentException('Organization number may contain letters, digits, spaces, and hyphens.');
        }
    }

    private function assertEmail(): void
    {
        if ($this->email === '') {
            return;
        }

        if (
            mb_strlen($this->email) > self::EMAIL_MAX
            || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $this->email) !== 1
        ) {
            throw new InvalidArgumentException('Association email must be empty or a single address.');
        }
    }

    private function assertPhone(): void
    {
        if ($this->phone === '') {
            return;
        }

        if (
            mb_strlen($this->phone) > self::PHONE_MAX
            || preg_match('/^[0-9+(). -]+$/', $this->phone) !== 1
        ) {
            throw new InvalidArgumentException('Phone may contain digits, spaces, and + ( ) - .');
        }
    }

    private function assertLanguage(): void
    {
        if (! in_array($this->language, [self::LANGUAGE_SWEDISH, self::LANGUAGE_ENGLISH], true)) {
            throw new InvalidArgumentException('Association language is sv or en.');
        }
    }

    private function assertLogo(): void
    {
        if ($this->logoAttachmentId !== null && $this->logoAttachmentId < 1) {
            throw new InvalidArgumentException('Logo attachment id must be empty or a positive id.');
        }
    }

    private function assertStartDay(): void
    {
        if (! checkdate($this->membershipYearStartMonth, $this->membershipYearStartDay, 2023)) {
            throw new InvalidArgumentException('Membership year starts on a real month and day, other than 29 February.');
        }
    }
}
