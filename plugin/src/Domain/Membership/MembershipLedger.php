<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

final class MembershipLedger
{
    /**
     * @param list<MembershipPeriod> $existing
     */
    public function add(array $existing, MembershipPeriod $candidate): void
    {
        foreach ($existing as $period) {
            if ($period->membershipId() === $candidate->membershipId() && $period->overlaps($candidate)) {
                throw new MembershipRuleException('Membership periods cannot overlap.');
            }
        }
    }

    public function end(MembershipPeriod $period, AssociationDate $on): MembershipPeriod
    {
        if ($period->status() === MembershipStatus::Ended) {
            throw new MembershipRuleException('Membership is already ended.');
        }

        if ($on->isBefore($period->startedOn())) {
            throw new MembershipRuleException('A membership cannot end before it starts.');
        }

        return new MembershipPeriod(
            $period->id(),
            $period->membershipId(),
            MembershipStatus::Ended,
            $period->startedOn(),
            $on,
            $period->historicalClass()
        );
    }

    /**
     * @param list<MembershipPeriod> $periods
     * @return list<MembershipPeriod>
     */
    public function endOpenPeriods(array $periods, AssociationDate $on): array
    {
        $ended = [];

        foreach ($periods as $period) {
            if ($period->status() === MembershipStatus::Ended) {
                $ended[] = $period;

                continue;
            }

            $ended[] = $this->end($period, $on);
        }

        return $ended;
    }
}
