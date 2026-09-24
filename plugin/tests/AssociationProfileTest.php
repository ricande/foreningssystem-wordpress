<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Domain\Association\AssociationProfile;
use Foreningssystem\Domain\Membership\AssociationDate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AssociationProfileTest extends TestCase
{
    public function test_an_empty_profile_uses_a_calendar_year(): void
    {
        $profile = AssociationProfile::empty();
        $year = $profile->membershipYear(AssociationDate::fromIso('2026-09-24'));

        self::assertSame('', $profile->name());
        self::assertSame(AssociationProfile::LANGUAGE_SWEDISH, $profile->language());
        self::assertNull($profile->logoAttachmentId());
        self::assertSame('01-01', $profile->membershipYearStart());
        self::assertSame('2026-01-01', $year->startedOn()->iso());
        self::assertSame('2026-12-31', $year->endedOn()->iso());
    }

    public function test_a_membership_year_may_start_after_new_year(): void
    {
        $profile = $this->profile(7, 1);
        $inside = $profile->membershipYear(AssociationDate::fromIso('2026-09-24'));
        $onStart = $profile->membershipYear(AssociationDate::fromIso('2026-07-01'));
        $beforeStart = $profile->membershipYear(AssociationDate::fromIso('2026-06-30'));
        $leapDay = $this->profile(3, 1)->membershipYear(AssociationDate::fromIso('2024-02-29'));

        self::assertSame('2026-07-01', $inside->startedOn()->iso());
        self::assertSame('2027-06-30', $inside->endedOn()->iso());
        self::assertSame('2026-07-01', $onStart->startedOn()->iso());
        self::assertSame('2025-07-01', $beforeStart->startedOn()->iso());
        self::assertSame('2026-06-30', $beforeStart->endedOn()->iso());
        self::assertSame('2023-03-01', $leapDay->startedOn()->iso());
        self::assertSame('2024-02-29', $leapDay->endedOn()->iso());
    }

    public function test_profile_fields_reject_a_broken_contact_or_start_day(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->profile(2, 29);
    }

    public function test_email_must_be_empty_or_one_address(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssociationProfile('Föreningen', '802001-1234', "Storgatan 1\n111 22 Stan", 'inte-en-adress', '08-123 45', AssociationProfile::LANGUAGE_ENGLISH, null, 1, 1);
    }

    private function profile(int $month, int $day): AssociationProfile
    {
        return new AssociationProfile(
            'Exempelföreningen',
            '802001-1234',
            "Storgatan 1\n111 22 Stan",
            'styrelse@example.test',
            '+46 8 123 45',
            AssociationProfile::LANGUAGE_ENGLISH,
            null,
            $month,
            $day
        );
    }
}
