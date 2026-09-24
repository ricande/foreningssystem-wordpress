<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Domain\Membership\AssociationDate;
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
        self::assertFalse($ended->countsAsActiveMember());
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

    public function test_only_an_open_active_period_counts_as_an_active_member(): void
    {
        self::assertTrue($this->period(1, '2024-01-01', null, MembershipStatus::Active)->countsAsActiveMember());
        self::assertFalse($this->period(1, '2024-01-01', null, MembershipStatus::Dormant)->countsAsActiveMember());
        self::assertFalse($this->period(1, '2024-01-01', '2024-02-01', MembershipStatus::Ended)->countsAsActiveMember());
    }

    private function period(?int $id, string $start, ?string $end, MembershipStatus $status): MembershipPeriod
    {
        $period = new MembershipPeriod(
            $id,
            7,
            'M-' . ($id ?? 'new') . '-' . $start,
            'ordinarie',
            $status,
            AssociationDate::fromIso($start),
            $end === null ? null : AssociationDate::fromIso($end)
        );

        return $period;
    }
}
