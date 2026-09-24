<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Domain\Membership\AssociationDate;
use InvalidArgumentException;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use PHPUnit\Framework\TestCase;

final class MembershipLedgerTest extends TestCase
{
    public function test_a_person_can_exist_without_a_membership(): void
    {
        $person = new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null);

        self::assertNull($person->id());
        self::assertSame(PersonStatus::Known, $person->status());
    }

    public function test_a_later_period_is_allowed_and_an_overlap_is_not(): void
    {
        $ledger = new MembershipLedger();
        $first = $this->period(1, '2020-01-01', '2021-12-31', MembershipStatus::Ended);
        $next = $this->period(null, '2022-01-01', null, MembershipStatus::Active);

        $ledger->add([$first], $next);

        $this->expectException(MembershipRuleException::class);
        $ledger->add([$first], $this->period(null, '2021-12-31', null, MembershipStatus::Active));
    }

    public function test_ending_a_membership_keeps_the_period_and_rejects_a_second_end(): void
    {
        $ledger = new MembershipLedger();
        $open = $this->period(4, '2024-01-01', null, MembershipStatus::Active);
        $ended = $ledger->end($open, AssociationDate::fromIso('2024-06-01'));

        self::assertSame(4, $ended->id());
        self::assertSame(MembershipStatus::Ended, $ended->status());
        self::assertTrue($ended->isActiveOn(AssociationDate::fromIso('2024-06-01')));
        self::assertFalse($ended->isActiveOn(AssociationDate::fromIso('2024-06-02')));
        self::assertSame('2024-06-01', $ended->endedOn()?->iso());

        $this->expectException(MembershipRuleException::class);
        $ledger->end($ended, AssociationDate::fromIso('2024-07-01'));
    }

    public function test_death_ends_open_memberships_without_removing_them(): void
    {
        $person = new Person(7, 'Grace', 'Hopper', '', PersonStatus::Known, null);
        $open = $this->period(3, '2019-03-01', null, MembershipStatus::Active);
        $alreadyEnded = $this->period(2, '2010-01-01', '2012-01-01', MembershipStatus::Ended);

        $deceased = $person->markedDeceased();
        $periods = (new MembershipLedger())->endOpenPeriods(
            [$alreadyEnded, $open],
            AssociationDate::fromIso('2024-09-24')
        );

        self::assertSame(PersonStatus::Deceased, $deceased->status());
        self::assertCount(2, $periods);
        self::assertSame(MembershipStatus::Ended, $periods[0]->status());
        self::assertSame('2012-01-01', $periods[0]->endedOn()?->iso());
        self::assertSame(MembershipStatus::Ended, $periods[1]->status());
        self::assertSame('2024-09-24', $periods[1]->endedOn()?->iso());
    }

    public function test_a_membership_is_active_only_on_dates_it_covers(): void
    {
        $today = AssociationDate::fromIso('2024-06-15');
        $open = $this->period(1, '2024-01-01', null, MembershipStatus::Active);

        self::assertTrue($open->isActiveOn(AssociationDate::fromIso('2024-06-14')));
        self::assertTrue($open->isActiveOn($today));
        self::assertTrue($this->period(2, '2024-06-15', null, MembershipStatus::Active)->isActiveOn($today));
        self::assertFalse($this->period(3, '2024-06-16', null, MembershipStatus::Active)->isActiveOn($today));
        self::assertFalse($this->period(4, '2024-01-01', '2024-06-14', MembershipStatus::Ended)->isActiveOn($today));
        self::assertTrue($this->period(5, '2024-01-01', '2024-06-15', MembershipStatus::Ended)->isActiveOn($today));
        self::assertFalse($this->period(6, '2024-01-01', null, MembershipStatus::Dormant)->isActiveOn($today));
        self::assertFalse($this->period(7, '2024-01-01', null, MembershipStatus::Pending)->isActiveOn($today));
        self::assertTrue($this->period(8, '2024-01-01', '2024-06-15', MembershipStatus::Active)->isActiveOn($today));
    }

    public function test_contradictory_period_status_and_dates_are_rejected(): void
    {
        try {
            $this->period(1, '2024-01-01', null, MembershipStatus::Ended);
            self::fail('An ended period needs an end date.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        try {
            $this->period(2, '2024-01-01', '2024-06-01', MembershipStatus::Pending);
            self::fail('A pending period cannot have an end date.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->period(3, '2024-01-01', '2024-06-01', MembershipStatus::Dormant);
    }

    private function period(?int $id, string $start, ?string $end, MembershipStatus $status): MembershipPeriod
    {
        $period = new MembershipPeriod(
            $id,
            7,
            $status,
            AssociationDate::fromIso($start),
            $end === null ? null : AssociationDate::fromIso($end),
            'ordinarie'
        );

        return $period;
    }
}
