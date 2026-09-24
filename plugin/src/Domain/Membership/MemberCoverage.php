<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

final class MemberCoverage
{
    /**
     * Coverage counts only where the period and the participation interval both include the date.
     * Start and end dates are inclusive, the same way a membership period is inclusive.
     *
     * @param list<MembershipParticipant> $participants
     * @param list<MembershipPeriod> $periods
     * @return list<MembershipPeriod>
     */
    public static function periodsCoveringAssignment(
        int $personId,
        AssociationDate $startedOn,
        ?AssociationDate $endedOn,
        array $participants,
        array $periods,
    ): array {
        $covered = [];

        foreach ($periods as $period) {
            if (! $period->coversAssignment($startedOn, $endedOn)) {
                continue;
            }

            foreach ($participants as $participant) {
                if (
                    $participant->personId() === $personId
                    && $participant->membershipId() === $period->membershipId()
                    && $participant->role()->countsAsMember()
                    && self::participationCovers($participant, $startedOn, $endedOn)
                ) {
                    $covered[] = $period;
                    break;
                }
            }
        }

        return $covered;
    }

    /**
     * @param list<MembershipParticipant> $participants
     * @param list<MembershipPeriod> $periods
     */
    public static function isActiveMember(
        int $personId,
        AssociationDate $on,
        array $participants,
        array $periods,
    ): bool {
        foreach ($participants as $participant) {
            if ($participant->personId() !== $personId || ! $participant->countsAsMemberOn($on)) {
                continue;
            }

            foreach ($periods as $period) {
                if ($period->membershipId() === $participant->membershipId() && $period->isActiveOn($on)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<MembershipPeriod> $periods
     */
    public static function membershipIsActive(int $membershipId, AssociationDate $on, array $periods): bool
    {
        foreach ($periods as $period) {
            if ($period->membershipId() === $membershipId && $period->isActiveOn($on)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<MembershipParticipant> $participants
     * @param list<MembershipPeriod> $periods
     */
    public static function retentionEnd(int $personId, array $participants, array $periods): ?AssociationDate
    {
        $hasMembership = false;
        $latest = null;

        foreach ($participants as $participant) {
            if ($participant->personId() !== $personId || ! $participant->role()->countsAsMember()) {
                continue;
            }

            foreach ($periods as $period) {
                if (! self::participationOverlapsPeriod($participant, $period)) {
                    continue;
                }

                $hasMembership = true;
                $end = self::coverageEnd($participant, $period);

                if (! $end instanceof AssociationDate) {
                    return null;
                }

                if ($latest === null || $end->isAfter($latest)) {
                    $latest = $end;
                }
            }
        }

        return $hasMembership ? $latest : null;
    }

    public static function participationOverlapsPeriod(MembershipParticipant $participant, MembershipPeriod $period): bool
    {
        if ($participant->membershipId() !== $period->membershipId() || ! self::coversDates($period)) {
            return false;
        }

        return self::rangesOverlap(
            $participant->startedOn(),
            $participant->endedOn(),
            $period->startedOn(),
            $period->endedOn()
        );
    }

    public static function coveragesOverlap(
        MembershipParticipant $leftParticipant,
        MembershipPeriod $leftPeriod,
        MembershipParticipant $rightParticipant,
        MembershipPeriod $rightPeriod,
    ): bool {
        if (
            ! self::participationOverlapsPeriod($leftParticipant, $leftPeriod)
            || ! self::participationOverlapsPeriod($rightParticipant, $rightPeriod)
        ) {
            return false;
        }

        return self::rangesOverlap(
            self::later($leftParticipant->startedOn(), $leftPeriod->startedOn()),
            self::earlier($leftParticipant->endedOn(), $leftPeriod->endedOn()),
            self::later($rightParticipant->startedOn(), $rightPeriod->startedOn()),
            self::earlier($rightParticipant->endedOn(), $rightPeriod->endedOn())
        );
    }

    private static function participationCovers(
        MembershipParticipant $participant,
        AssociationDate $startedOn,
        ?AssociationDate $endedOn,
    ): bool {
        if ($startedOn->isBefore($participant->startedOn())) {
            return false;
        }

        if (! $participant->endedOn() instanceof AssociationDate) {
            return true;
        }

        if (! $endedOn instanceof AssociationDate) {
            return false;
        }

        return ! $endedOn->isAfter($participant->endedOn());
    }

    private static function coverageEnd(MembershipParticipant $participant, MembershipPeriod $period): ?AssociationDate
    {
        if (
            ! $participant->endedOn() instanceof AssociationDate
            && ! $period->endedOn() instanceof AssociationDate
            && $period->status() === MembershipStatus::Active
        ) {
            return null;
        }

        return self::earlier($participant->endedOn(), $period->endedOn());
    }

    private static function coversDates(MembershipPeriod $period): bool
    {
        return $period->status() === MembershipStatus::Active || $period->status() === MembershipStatus::Ended;
    }

    private static function rangesOverlap(
        AssociationDate $leftStart,
        ?AssociationDate $leftEnd,
        AssociationDate $rightStart,
        ?AssociationDate $rightEnd,
    ): bool {
        $leftStartsBeforeRightEnds = ! $rightEnd instanceof AssociationDate || ! $leftStart->isAfter($rightEnd);
        $rightStartsBeforeLeftEnds = ! $leftEnd instanceof AssociationDate || ! $rightStart->isAfter($leftEnd);

        return $leftStartsBeforeRightEnds && $rightStartsBeforeLeftEnds;
    }

    private static function later(AssociationDate $left, AssociationDate $right): AssociationDate
    {
        return $left->isAfter($right) ? $left : $right;
    }

    private static function earlier(?AssociationDate $left, ?AssociationDate $right): ?AssociationDate
    {
        if (! $left instanceof AssociationDate) {
            return $right;
        }

        if (! $right instanceof AssociationDate) {
            return $left;
        }

        return $left->isBefore($right) ? $left : $right;
    }
}
