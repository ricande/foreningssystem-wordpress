<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

/**
 * Rules for adding a participation interval.
 * Callers in the application and in CSV import use the same checks.
 */
final class ParticipantAdmission
{
    public static function assertRole(MembershipKind $kind, ParticipantRole $role, bool $deceased): void
    {
        if ($kind === MembershipKind::Company && $role->countsAsMember()) {
            throw new MembershipRuleException('A company contact is not an individual member.');
        }

        if ($deceased && $role->countsAsMember()) {
            throw new MembershipRuleException('A deceased person cannot start a membership.');
        }
    }

    /**
     * @param list<MembershipParticipant> $sameMembership
     * @param list<MembershipPeriod> $candidatePeriods
     * @param list<MembershipParticipant> $allParticipants
     * @param list<MembershipPeriod> $allPeriods
     */
    public static function assertNoOverlap(
        MembershipParticipant $incoming,
        array $sameMembership,
        array $candidatePeriods,
        array $allParticipants,
        array $allPeriods,
    ): void {
        foreach ($sameMembership as $existing) {
            if ($existing->personId() === $incoming->personId() && $existing->overlaps($incoming)) {
                throw new MembershipRuleException('The person already participates in this membership.');
            }
        }

        if (! $incoming->role()->countsAsMember()) {
            return;
        }

        foreach ($allParticipants as $participant) {
            if (
                $participant->personId() !== $incoming->personId()
                || ! $participant->role()->countsAsMember()
                || $participant->membershipId() === $incoming->membershipId()
            ) {
                continue;
            }

            foreach ($allPeriods as $existing) {
                if ($existing->membershipId() !== $participant->membershipId()) {
                    continue;
                }

                foreach ($candidatePeriods as $candidate) {
                    if (MemberCoverage::coveragesOverlap($incoming, $candidate, $participant, $existing)) {
                        throw new MembershipRuleException('Membership periods cannot overlap.');
                    }
                }
            }
        }
    }
}
