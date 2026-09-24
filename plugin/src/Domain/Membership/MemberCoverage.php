<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

final class MemberCoverage
{
    /**
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
                if ($period->membershipId() !== $participant->membershipId()) {
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

    private static function participationCovers(
        MembershipParticipant $participant,
        AssociationDate $startedOn,
        ?AssociationDate $endedOn,
    ): bool {
        if (! $participant->endedOn() instanceof AssociationDate) {
            return true;
        }

        if (! $endedOn instanceof AssociationDate) {
            return false;
        }

        return ! $endedOn->isAfter($participant->endedOn()) && ! $startedOn->isAfter($participant->endedOn());
    }

    private static function coverageEnd(MembershipParticipant $participant, MembershipPeriod $period): ?AssociationDate
    {
        if ($participant->endedOn() instanceof AssociationDate) {
            if ($period->endedOn() instanceof AssociationDate && $period->endedOn()->isBefore($participant->endedOn())) {
                return $period->endedOn();
            }

            return $participant->endedOn();
        }

        return $period->endedOn();
    }
}
