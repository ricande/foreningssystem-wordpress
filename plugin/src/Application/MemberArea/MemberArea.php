<?php

declare(strict_types=1);

namespace Foreningssystem\Application\MemberArea;

use Foreningssystem\Application\Document\WordPressIdentity;
use Foreningssystem\Domain\Identity\PersonalIdentityRecord;
use Foreningssystem\Domain\Identity\PersonalIdentityRepository;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\EffectiveCoverage;
use Foreningssystem\Domain\Membership\MemberCoverage;
use Foreningssystem\Domain\Membership\Membership;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;
use Foreningssystem\Domain\Person\PersonStatus;

final class MemberArea
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly MembershipRepository $memberships,
        private readonly PersonalIdentityRepository $identities,
        private readonly WordPressIdentity $accounts,
    ) {
    }

    public function open(int $wordpressUserId, AssociationDate $on): MemberAreaSnapshot
    {
        if ($wordpressUserId < 1 || ! $this->accounts->exists($wordpressUserId)) {
            return MemberAreaSnapshot::loggedOut();
        }

        $person = $this->people->findByWordpressUserId($wordpressUserId);

        if (! $person instanceof Person || $person->id() === null) {
            return MemberAreaSnapshot::unlinked();
        }

        if ($person->status() === PersonStatus::Deceased) {
            return MemberAreaSnapshot::unavailable();
        }

        $personId = $person->id();
        $participants = $this->memberships->allParticipants();
        $periods = $this->memberships->all();
        $memberships = [];

        foreach ($this->memberships->allMemberships() as $membership) {
            $membershipId = $membership->id();

            if ($membershipId !== null) {
                $memberships[$membershipId] = $membership;
            }
        }

        $accountEmail = $this->accounts->email($wordpressUserId);
        $birthDate = $person->birthDate();

        return new MemberAreaSnapshot(
            MemberAreaState::Linked,
            trim($person->firstName() . ' ' . $person->lastName()),
            $person->email(),
            $birthDate?->iso(),
            $this->identities->findForPerson($personId) instanceof PersonalIdentityRecord,
            $accountEmail,
            $this->emailsDiffer($person->email(), $accountEmail),
            MemberCoverage::isActiveMember($personId, $on, $participants, $periods),
            $this->coverages($personId, $on, $participants, $periods, $memberships)
        );
    }

    /**
     * @param list<\Foreningssystem\Domain\Membership\MembershipParticipant> $participants
     * @param list<\Foreningssystem\Domain\Membership\MembershipPeriod> $periods
     * @param array<int, Membership> $memberships
     * @return list<MemberAreaCoverage>
     */
    private function coverages(int $personId, AssociationDate $on, array $participants, array $periods, array $memberships): array
    {
        $rows = [];

        foreach (MemberCoverage::effectiveMemberCoverages($personId, $participants, $periods) as $coverage) {
            $membership = $memberships[$coverage->membershipId()] ?? null;

            if (! $membership instanceof Membership) {
                continue;
            }

            $rows[] = [
                'coverage' => new MemberAreaCoverage(
                    $membership->number(),
                    $membership->kind(),
                    $coverage->startedOn()->iso(),
                    $coverage->endedOn()?->iso(),
                    $this->timing($coverage, $on)
                ),
                'membershipId' => $coverage->membershipId(),
            ];
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                $rank = [
                    MemberAreaTiming::Current->value => 0,
                    MemberAreaTiming::Future->value => 1,
                    MemberAreaTiming::Ended->value => 2,
                ];
                /** @var MemberAreaCoverage $leftCoverage */
                $leftCoverage = $left['coverage'];
                /** @var MemberAreaCoverage $rightCoverage */
                $rightCoverage = $right['coverage'];
                $leftRank = $rank[$leftCoverage->timing->value];
                $rightRank = $rank[$rightCoverage->timing->value];

                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }

                if ($leftCoverage->timing === MemberAreaTiming::Ended) {
                    $byEnd = ($rightCoverage->to ?? '') <=> ($leftCoverage->to ?? '');

                    if ($byEnd !== 0) {
                        return $byEnd;
                    }
                } else {
                    $byStart = $leftCoverage->from <=> $rightCoverage->from;

                    if ($byStart !== 0) {
                        return $byStart;
                    }
                }

                return $left['membershipId'] <=> $right['membershipId'];
            }
        );

        return array_map(static fn (array $row): MemberAreaCoverage => $row['coverage'], $rows);
    }

    private function timing(EffectiveCoverage $coverage, AssociationDate $on): MemberAreaTiming
    {
        if ($coverage->includes($on)) {
            return MemberAreaTiming::Current;
        }

        if ($coverage->startedOn()->isAfter($on)) {
            return MemberAreaTiming::Future;
        }

        return MemberAreaTiming::Ended;
    }

    private function emailsDiffer(string $left, string $right): bool
    {
        $normalizedLeft = strtolower(trim($left));
        $normalizedRight = strtolower(trim($right));

        return $normalizedLeft !== '' && $normalizedRight !== '' && $normalizedLeft !== $normalizedRight;
    }
}
