<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

/**
 * Rules for adding a participation interval.
 * Callers in the application and in CSV import use the same checks.
 */
final class ParticipantAdmission
{
    /**
     * Ordinary, youth and family memberships take members.
     * A company membership takes contacts.
     * Ordinary and youth memberships belong to one person; a later interval for that same person is still allowed.
     *
     * @param list<MembershipParticipant> $existing
     */
    public static function assertRole(
        MembershipKind $kind,
        ParticipantRole $role,
        bool $deceased,
        array $existing = [],
        ?int $incomingPersonId = null,
    ): void {
        $allowed = $kind === MembershipKind::Company ? ParticipantRole::Contact : ParticipantRole::Member;

        if ($role !== $allowed) {
            if ($kind === MembershipKind::Company) {
                throw new MembershipRuleException('A company contact is not an individual member.');
            }

            throw new MembershipRuleException('Company contacts can only be added to company memberships.');
        }

        if ($deceased && $role->countsAsMember()) {
            throw new MembershipRuleException('A deceased person cannot start a membership.');
        }

        if ($kind !== MembershipKind::Ordinary && $kind !== MembershipKind::Youth) {
            return;
        }

        foreach ($existing as $participant) {
            if (! $participant->role()->countsAsMember()) {
                continue;
            }

            if ($incomingPersonId !== null && $participant->personId() === $incomingPersonId) {
                continue;
            }

            throw new MembershipRuleException('An ordinary or youth membership has one member.');
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
