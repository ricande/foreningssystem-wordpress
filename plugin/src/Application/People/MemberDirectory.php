<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MemberCoverage;
use Foreningssystem\Domain\Membership\Membership;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipParticipant;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Organization\Organization;
use Foreningssystem\Domain\Organization\OrganizationRepository;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;
use Foreningssystem\Domain\Person\PersonStatus;

/**
 * One read of people, memberships and organizations for the members screen.
 * It does not include personal identity numbers.
 */
final class MemberDirectory
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly MembershipRepository $memberships,
        private readonly OrganizationRepository $organizations,
    ) {
    }

    /**
     * @return list<array{title: string, number: string, kind: string, state: string, dates: string, context: string, person_id: int, membership_number: string, has_history: bool}>
     */
    public function listRows(string $query, string $kind, string $state, AssociationDate $today): array
    {
        $snapshot = $this->snapshot();
        $rows = [];

        foreach ($snapshot['people'] as $person) {
            $personId = (int) $person->id();
            $view = $this->personView($person, $snapshot, $today);

            if ($view === null) {
                continue;
            }

            $rows[] = $view;
        }

        foreach ($snapshot['memberships'] as $membership) {
            if ($membership->kind() !== MembershipKind::Company) {
                continue;
            }

            $rows[] = $this->companyRow($membership, $snapshot, $today);
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => self::matches($row, $query, $kind, $state)
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function personDetail(int $personId, AssociationDate $today): ?array
    {
        $person = $this->people->find($personId);

        if (! $person instanceof Person || $person->id() === null) {
            return null;
        }

        $snapshot = $this->snapshot();
        $participants = $snapshot['participantsByPerson'][$personId] ?? [];
        $memberships = [];

        foreach ($participants as $participant) {
            $membership = $snapshot['memberships'][$participant->membershipId()] ?? null;

            if (! $membership instanceof Membership || isset($memberships[$participant->membershipId()])) {
                continue;
            }

            $memberships[$participant->membershipId()] = $this->membershipView($membership, $snapshot);
        }

        return [
            'person_id' => $personId,
            'first_name' => $person->firstName(),
            'last_name' => $person->lastName(),
            'email' => $person->email(),
            'birth_date' => $person->birthDate()?->iso(),
            'deceased' => $person->status() === PersonStatus::Deceased,
            'active_member' => MemberCoverage::isActiveMember($personId, $today, $snapshot['participants'], $snapshot['periods']),
            'memberships' => array_values($memberships),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function membershipDetail(string $number): ?array
    {
        $membership = $this->memberships->findMembershipByNumber(trim($number));

        if (! $membership instanceof Membership || $membership->id() === null) {
            return null;
        }

        return $this->membershipView($membership, $this->snapshot());
    }

    /**
     * @return list<array{person_id: int, label: string}>
     */
    public function peopleChoices(): array
    {
        $choices = [];

        foreach ($this->people->all() as $person) {
            if ($person->id() === null || $person->status() === PersonStatus::Deceased) {
                continue;
            }

            $label = $person->firstName() . ' ' . $person->lastName();

            if ($person->email() !== '') {
                $label .= ', ' . $person->email();
            }

            $choices[] = ['person_id' => $person->id(), 'label' => $label];
        }

        usort($choices, static fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']));

        return $choices;
    }

    /**
     * @return array{people: array<int, Person>, memberships: array<int, Membership>, periods: list<MembershipPeriod>, participants: list<MembershipParticipant>, participantsByPerson: array<int, list<MembershipParticipant>>, participantsByMembership: array<int, list<MembershipParticipant>>, periodsByMembership: array<int, list<MembershipPeriod>>, organizations: array<int, Organization>}
     */
    private function snapshot(): array
    {
        $people = [];

        foreach ($this->people->all() as $person) {
            if ($person->id() !== null) {
                $people[$person->id()] = $person;
            }
        }

        $memberships = [];

        foreach ($this->memberships->allMemberships() as $membership) {
            if ($membership->id() !== null) {
                $memberships[$membership->id()] = $membership;
            }
        }

        $organizations = [];

        foreach ($this->organizations->all() as $organization) {
            if ($organization->id() !== null) {
                $organizations[$organization->id()] = $organization;
            }
        }

        $periods = $this->memberships->all();
        $participants = $this->memberships->allParticipants();
        $periodsByMembership = [];
        $participantsByMembership = [];
        $participantsByPerson = [];

        foreach ($periods as $period) {
            $periodsByMembership[$period->membershipId()][] = $period;
        }

        foreach ($participants as $participant) {
            $participantsByMembership[$participant->membershipId()][] = $participant;
            $participantsByPerson[$participant->personId()][] = $participant;
        }

        return [
            'people' => $people,
            'memberships' => $memberships,
            'periods' => $periods,
            'participants' => $participants,
            'participantsByPerson' => $participantsByPerson,
            'participantsByMembership' => $participantsByMembership,
            'periodsByMembership' => $periodsByMembership,
            'organizations' => $organizations,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{title: string, number: string, kind: string, state: string, dates: string, context: string, person_id: int, membership_number: string, has_history: bool}|null
     */
    private function personView(Person $person, array $snapshot, AssociationDate $today): ?array
    {
        $personId = (int) $person->id();
        $links = $snapshot['participantsByPerson'][$personId] ?? [];
        $activeMember = MemberCoverage::isActiveMember($personId, $today, $snapshot['participants'], $snapshot['periods']);
        $primary = null;
        $context = [];
        $hasHistory = false;

        foreach ($links as $participant) {
            $membership = $snapshot['memberships'][$participant->membershipId()] ?? null;

            if (! $membership instanceof Membership) {
                continue;
            }

            if ($participant->role()->countsAsMember() && $participant->endedOn() instanceof AssociationDate) {
                $hasHistory = true;
            }

            foreach ($snapshot['periodsByMembership'][$membership->id()] ?? [] as $period) {
                if ($period->status() === MembershipStatus::Ended && MemberCoverage::participationOverlapsPeriod($participant, $period)) {
                    $hasHistory = true;
                }
            }

            if ($participant->role()->countsAsMember()) {
                $open = ! $participant->endedOn() instanceof AssociationDate;
                $primaryIsClosed = is_array($primary) && $primary[0]->endedOn() instanceof AssociationDate;

                if ($primary === null || ($open && $primaryIsClosed)) {
                    $primary = [$participant, $membership];
                }
            }

            if (! $participant->role()->countsAsMember() && $membership->kind() === MembershipKind::Company) {
                $organization = $snapshot['organizations'][$membership->organizationId()] ?? null;
                $context[] = $organization instanceof Organization ? $organization->name() : $membership->number();
            }
        }

        if ($person->status() === PersonStatus::Deceased) {
            $state = 'deceased';
        } elseif ($activeMember) {
            $state = 'active';
        } elseif ($hasHistory) {
            $state = 'history';
        } elseif ($context !== []) {
            $state = 'contact';
        } else {
            $state = 'inactive';
        }

        $number = '';
        $kind = '';
        $dates = '';

        if (is_array($primary)) {
            [$participant, $membership] = $primary;
            $number = $membership->number();
            $kind = $membership->kind()->value;
            $dates = $this->dateSpan($participant->startedOn()->iso(), $participant->endedOn()?->iso());
        }

        return [
            'title' => $person->firstName() . ' ' . $person->lastName(),
            'number' => $number,
            'kind' => $kind,
            'state' => $state,
            'dates' => $dates,
            'context' => implode(', ', $context),
            'email' => $person->email(),
            'person_id' => $personId,
            'membership_number' => '',
            'has_history' => $hasHistory,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{title: string, number: string, kind: string, state: string, dates: string, context: string, person_id: int, membership_number: string, has_history: bool}
     */
    private function companyRow(Membership $membership, array $snapshot, AssociationDate $today): array
    {
        $organization = $snapshot['organizations'][$membership->organizationId()] ?? null;
        $open = null;

        foreach ($snapshot['periodsByMembership'][(int) $membership->id()] ?? [] as $period) {
            if ($period->status() !== MembershipStatus::Ended) {
                $open = $period;
            }
        }

        $latest = $open;

        if (! $latest instanceof MembershipPeriod) {
            foreach ($snapshot['periodsByMembership'][(int) $membership->id()] ?? [] as $period) {
                $latest = $period;
            }
        }

        $active = MemberCoverage::membershipIsActive((int) $membership->id(), $today, $snapshot['periods']);
        $hasHistory = false;

        foreach ($snapshot['periodsByMembership'][(int) $membership->id()] ?? [] as $period) {
            if ($period->status() === MembershipStatus::Ended) {
                $hasHistory = true;
            }
        }

        return [
            'title' => $organization instanceof Organization ? $organization->name() : $membership->number(),
            'number' => $membership->number(),
            'kind' => MembershipKind::Company->value,
            'state' => $active ? 'active' : 'history',
            'dates' => $latest instanceof MembershipPeriod ? $this->dateSpan($latest->startedOn()->iso(), $latest->endedOn()?->iso()) : '',
            'context' => $organization instanceof Organization ? ($organization->number()?->canonical() ?? '') : '',
            'email' => '',
            'person_id' => 0,
            'membership_number' => $membership->number(),
            'has_history' => $hasHistory,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function membershipView(Membership $membership, array $snapshot): array
    {
        $organization = $snapshot['organizations'][$membership->organizationId()] ?? null;
        $periods = [];

        foreach ($snapshot['periodsByMembership'][(int) $membership->id()] ?? [] as $period) {
            $periods[] = [
                'period_id' => (int) $period->id(),
                'started_on' => $period->startedOn()->iso(),
                'ended_on' => $period->endedOn()?->iso(),
                'status' => $period->status()->value,
                'open' => $period->status() !== MembershipStatus::Ended,
            ];
        }

        usort($periods, static fn (array $left, array $right): int => $left['started_on'] <=> $right['started_on']);
        $participants = [];

        foreach ($snapshot['participantsByMembership'][(int) $membership->id()] ?? [] as $participant) {
            $person = $snapshot['people'][$participant->personId()] ?? null;
            $participants[] = [
                'person_id' => $participant->personId(),
                'name' => $person instanceof Person ? $person->firstName() . ' ' . $person->lastName() : '',
                'role' => $participant->role()->value,
                'primary' => $participant->isPrimary(),
                'started_on' => $participant->startedOn()->iso(),
                'ended_on' => $participant->endedOn()?->iso(),
                'open' => ! $participant->endedOn() instanceof AssociationDate,
            ];
        }

        usort($participants, static fn (array $left, array $right): int => [$left['name'], $left['started_on']] <=> [$right['name'], $right['started_on']]);

        return [
            'membership_id' => (int) $membership->id(),
            'number' => $membership->number(),
            'kind' => $membership->kind()->value,
            'organization' => $organization instanceof Organization ? $organization->name() : '',
            'organization_number' => $organization instanceof Organization ? ($organization->number()?->canonical() ?? '') : '',
            'periods' => $periods,
            'participants' => $participants,
        ];
    }

    private function dateSpan(string $startedOn, ?string $endedOn): string
    {
        return $startedOn . '|' . ($endedOn ?? '');
    }

    /**
     * @param array{title: string, number: string, kind: string, state: string, dates: string, context: string, person_id: int, membership_number: string, has_history: bool} $row
     */
    private static function matches(array $row, string $query, string $kind, string $state): bool
    {
        if ($kind !== '' && $row['kind'] !== $kind) {
            return false;
        }

        if ($state === 'active' && $row['state'] !== 'active') {
            return false;
        }

        if ($state === 'inactive' && ! in_array($row['state'], ['inactive', 'contact', 'history', 'deceased'], true)) {
            return false;
        }

        if ($state === 'history' && $row['has_history'] !== true) {
            return false;
        }

        if ($query === '') {
            return true;
        }

        $haystack = strtolower($row['title'] . ' ' . $row['email'] . ' ' . $row['number'] . ' ' . $row['context'] . ' ' . $row['membership_number']);

        return str_contains($haystack, strtolower($query));
    }
}
