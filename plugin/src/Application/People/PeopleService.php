<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MemberCoverage;
use Foreningssystem\Domain\Membership\Membership;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipParticipant;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;
use Foreningssystem\Domain\Person\PersonStatus;
use InvalidArgumentException;

final class PeopleService
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly MembershipRepository $memberships,
        private readonly MembershipLedger $ledger,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
        private readonly OpenBoardAssignments $openAssignments,
    ) {
    }

    public function register(
        string $firstName,
        string $lastName,
        string $email,
        string $membershipNumber,
        string $membershipType,
        AssociationDate $startedOn,
        ?AssociationDate $birthDate = null,
        ?AssociationDate $today = null,
    ): int {
        $this->require(Capabilities::EDIT_MEMBERS);

        return $this->transaction->run(function () use ($firstName, $lastName, $email, $membershipNumber, $membershipType, $startedOn, $birthDate, $today): int {
            $person = $this->people->add(new Person(
                null,
                trim($firstName),
                trim($lastName),
                trim($email),
                PersonStatus::Known,
                null,
                $this->birthDate($birthDate, $today ?? $startedOn)
            ));
            $personId = $person->id();

            if ($personId === null) {
                throw new \RuntimeException('The person was not saved.');
            }

            $this->openMembership($personId, trim($membershipNumber), trim($membershipType), $startedOn, ParticipantRole::Member, true);

            return $personId;
        });
    }

    public function rememberPerson(
        string $firstName,
        string $lastName,
        string $email,
        ?AssociationDate $birthDate = null,
        ?AssociationDate $today = null,
    ): int {
        $this->require(Capabilities::EDIT_MEMBERS);
        $person = $this->people->add(new Person(
            null,
            trim($firstName),
            trim($lastName),
            trim($email),
            PersonStatus::Known,
            null,
            $this->birthDate($birthDate, $today ?? AssociationDate::fromIso('1970-01-01'))
        ));

        return (int) $person->id();
    }

    public function addPersonToMembership(
        int $membershipId,
        string $firstName,
        string $lastName,
        string $email,
        ?AssociationDate $birthDate,
        AssociationDate $today,
        ParticipantRole $role,
        bool $primary,
    ): int {
        $this->require(Capabilities::EDIT_MEMBERS);

        return $this->transaction->run(function () use ($membershipId, $firstName, $lastName, $email, $birthDate, $today, $role, $primary): int {
            $membership = $this->requireAccount($membershipId);

            if ($membership->kind() === MembershipKind::Company && $role->countsAsMember()) {
                throw new MembershipRuleException('A company contact is not an individual member.');
            }

            $person = $this->people->add(new Person(
                null,
                trim($firstName),
                trim($lastName),
                trim($email),
                PersonStatus::Known,
                null,
                $this->birthDate($birthDate, $today)
            ));
            $personId = (int) $person->id();
            $this->memberships->addParticipant(new MembershipParticipant(null, $membershipId, $personId, $role, $primary, null));

            if ($role->countsAsMember()) {
                $this->assertPersonCoverage($personId, $this->openPeriods($membershipId), $today);
            }

            return $personId;
        });
    }

    public function addParticipant(
        int $membershipId,
        int $personId,
        ParticipantRole $role,
        bool $primary,
        AssociationDate $on,
    ): void {
        $this->require(Capabilities::EDIT_MEMBERS);
        $person = $this->requirePerson($personId);
        $membership = $this->requireAccount($membershipId);

        if ($membership->kind() === MembershipKind::Company && $role->countsAsMember()) {
            throw new MembershipRuleException('A company contact is not an individual member.');
        }

        if ($person->status() === PersonStatus::Deceased && $role->countsAsMember()) {
            throw new MembershipRuleException('A deceased person cannot start a membership.');
        }

        $this->transaction->run(function () use ($membership, $personId, $role, $primary, $on): void {
            foreach ($this->memberships->participantsForMembership((int) $membership->id()) as $existing) {
                if ($existing->personId() === $personId) {
                    throw new MembershipRuleException('The person already participates in this membership.');
                }
            }

            if ($role->countsAsMember()) {
                $this->assertPersonCoverage($personId, $this->openPeriods((int) $membership->id()), $on);
            }

            $this->memberships->addParticipant(new MembershipParticipant(
                null,
                (int) $membership->id(),
                $personId,
                $role,
                $primary,
                null
            ));
        });
    }

    public function addPeriod(int $membershipId, AssociationDate $startedOn): int
    {
        $this->require(Capabilities::EDIT_MEMBERS);
        $membership = $this->requireAccount($membershipId);
        $members = $this->memberParticipants($membershipId);

        if ($members === []) {
            throw new MembershipRuleException('The membership has no member to continue.');
        }

        foreach ($members as $participant) {
            if ($this->requirePerson($participant->personId())->status() === PersonStatus::Deceased) {
                throw new MembershipRuleException('A deceased person cannot start a membership.');
            }
        }

        return $this->transaction->run(function () use ($membership, $startedOn, $members): int {
            $period = new MembershipPeriod(null, (int) $membership->id(), MembershipStatus::Active, $startedOn, null, $membership->kind()->value);
            $this->ledger->add($this->memberships->periodsForMembership((int) $membership->id()), $period);

            foreach ($members as $participant) {
                $this->assertPersonCoverage($participant->personId(), [$period], $startedOn);
            }

            $saved = $this->memberships->add($period);
            $periodId = $saved->id();

            if ($periodId === null) {
                throw new \RuntimeException('The membership was not saved.');
            }

            return $periodId;
        });
    }

    public function endMembership(int $periodId, AssociationDate $on): void
    {
        $this->require(Capabilities::EDIT_MEMBERS);
        $period = $this->requirePeriod($periodId);

        $this->transaction->run(function () use ($period, $on): void {
            foreach ($this->memberParticipants($period->membershipId()) as $participant) {
                $this->openAssignments->endOpen($participant->personId(), $on);
            }

            $this->memberships->save($this->ledger->end($period, $on));
        });
    }

    public function markDeceased(int $personId, AssociationDate $on): void
    {
        $this->require(Capabilities::EDIT_MEMBERS);
        $person = $this->requirePerson($personId);

        $this->transaction->run(function () use ($person, $personId, $on): void {
            $this->openAssignments->endOpen($personId, $on);

            foreach ($this->memberships->allParticipants() as $participant) {
                if ($participant->personId() !== $personId || ! $participant->role()->countsAsMember() || $participant->endedOn() instanceof AssociationDate) {
                    continue;
                }

                $others = 0;

                foreach ($this->memberParticipants($participant->membershipId()) as $member) {
                    if ($member->personId() !== $personId) {
                        $others++;
                    }
                }

                if ($others === 0) {
                    foreach ($this->ledger->endOpenPeriods($this->memberships->periodsForMembership($participant->membershipId()), $on) as $period) {
                        $this->memberships->save($period);
                    }
                }

                $this->memberships->saveParticipant($participant->ended($on));
            }

            $this->people->save($person->markedDeceased());
        });
    }

    public function activeMemberCount(AssociationDate $on): int
    {
        $this->require(Capabilities::VIEW_MEMBERS);

        return $this->countIndividuals($on);
    }

    public function activeMembershipCount(AssociationDate $on): int
    {
        $this->require(Capabilities::VIEW_MEMBERS);
        $periods = $this->memberships->all();
        $count = 0;

        foreach ($this->memberships->allMemberships() as $membership) {
            $id = $membership->id();

            if ($id !== null && MemberCoverage::membershipIsActive($id, $on, $periods)) {
                $count++;
            }
        }

        return $count;
    }

    public function publicMemberCount(AssociationDate $on): int
    {
        return $this->countIndividuals($on);
    }

    /**
     * @return list<PersonRecord>
     */
    public function listPeople(): array
    {
        $this->require(Capabilities::VIEW_MEMBERS);
        $periods = $this->memberships->all();
        $participants = $this->memberships->allParticipants();
        $accounts = [];

        foreach ($this->memberships->allMemberships() as $membership) {
            if ($membership->id() !== null) {
                $accounts[$membership->id()] = $membership;
            }
        }

        $records = [];

        foreach ($this->people->all() as $person) {
            $personId = $person->id();

            if ($personId === null) {
                continue;
            }

            $latest = null;
            $account = null;

            foreach ($participants as $participant) {
                if ($participant->personId() !== $personId) {
                    continue;
                }

                foreach ($periods as $period) {
                    if ($period->membershipId() !== $participant->membershipId()) {
                        continue;
                    }

                    if (! $latest instanceof MembershipPeriod || $period->startedOn()->isAfter($latest->startedOn())) {
                        $latest = $period;
                        $account = $accounts[$period->membershipId()] ?? null;
                    }
                }
            }

            $records[] = new PersonRecord($person, $latest, $account);
        }

        return $records;
    }

    private function openMembership(
        int $personId,
        string $number,
        string $membershipType,
        AssociationDate $startedOn,
        ParticipantRole $role,
        bool $primary,
    ): Membership {
        if ($this->memberships->findMembershipByNumber($number) instanceof Membership) {
            throw new MembershipRuleException('Membership number is already used.');
        }

        $kind = MembershipKind::knownSlug($membershipType) ? MembershipKind::fromSlug($membershipType) : MembershipKind::Ordinary;

        if ($kind === MembershipKind::Company) {
            throw new MembershipRuleException('A company membership belongs to an organization.');
        }

        $membership = $this->memberships->addMembership(new Membership(null, $number, $kind, null));
        $membershipId = $membership->id();

        if ($membershipId === null) {
            throw new \RuntimeException('The membership was not saved.');
        }

        $this->memberships->addParticipant(new MembershipParticipant(null, $membershipId, $personId, $role, $primary, null));
        $period = new MembershipPeriod(null, $membershipId, MembershipStatus::Active, $startedOn, null, $membershipType);

        if ($role->countsAsMember()) {
            $this->assertPersonCoverage($personId, [$period], $startedOn);
        }

        $this->ledger->add([], $period);
        $this->memberships->add($period);

        return $membership;
    }

    public function openForExistingPerson(int $personId, string $membershipNumber, string $membershipType, AssociationDate $startedOn): int
    {
        $this->require(Capabilities::EDIT_MEMBERS);
        $person = $this->requirePerson($personId);

        if ($person->status() === PersonStatus::Deceased) {
            throw new MembershipRuleException('A deceased person cannot start a membership.');
        }

        return $this->transaction->run(function () use ($personId, $membershipNumber, $membershipType, $startedOn): int {
            $membership = $this->openMembership($personId, trim($membershipNumber), trim($membershipType), $startedOn, ParticipantRole::Member, true);

            return (int) $membership->id();
        });
    }

    /**
     * @param list<MembershipPeriod> $candidatePeriods
     */
    private function assertPersonCoverage(int $personId, array $candidatePeriods, AssociationDate $on): void
    {
        unset($on);

        foreach ($this->memberships->allParticipants() as $participant) {
            if ($participant->personId() !== $personId || ! $participant->role()->countsAsMember() || $participant->endedOn() instanceof AssociationDate) {
                continue;
            }

            foreach ($this->memberships->periodsForMembership($participant->membershipId()) as $existing) {
                foreach ($candidatePeriods as $candidate) {
                    if ($existing->membershipId() !== $candidate->membershipId() && $existing->overlaps($candidate)) {
                        throw new MembershipRuleException('Membership periods cannot overlap.');
                    }
                }
            }
        }
    }

    /**
     * @return list<MembershipParticipant>
     */
    private function memberParticipants(int $membershipId): array
    {
        $members = [];

        foreach ($this->memberships->participantsForMembership($membershipId) as $participant) {
            if ($participant->role()->countsAsMember() && ! $participant->endedOn() instanceof AssociationDate) {
                $members[] = $participant;
            }
        }

        return $members;
    }

    /**
     * @return list<MembershipPeriod>
     */
    private function openPeriods(int $membershipId): array
    {
        $open = [];

        foreach ($this->memberships->periodsForMembership($membershipId) as $period) {
            if ($period->status() !== MembershipStatus::Ended) {
                $open[] = $period;
            }
        }

        return $open;
    }

    private function countIndividuals(AssociationDate $on): int
    {
        $participants = $this->memberships->allParticipants();
        $periods = $this->memberships->all();
        $people = [];

        foreach ($participants as $participant) {
            if (MemberCoverage::isActiveMember($participant->personId(), $on, $participants, $periods)) {
                $people[$participant->personId()] = true;
            }
        }

        return count($people);
    }

    private function birthDate(?AssociationDate $birthDate, AssociationDate $today): ?AssociationDate
    {
        if ($birthDate instanceof AssociationDate && $birthDate->isAfter($today)) {
            throw new InvalidArgumentException('Birth date cannot be in the future.');
        }

        return $birthDate;
    }

    private function require(string $capability): void
    {
        if (! $this->authorizer->allows($capability)) {
            throw new NotAllowed($capability);
        }
    }

    private function requirePerson(int $personId): Person
    {
        $person = $this->people->find($personId);

        if (! $person instanceof Person) {
            throw new \RuntimeException('Person was not found.');
        }

        return $person;
    }

    private function requireAccount(int $membershipId): Membership
    {
        $membership = $this->memberships->findMembership($membershipId);

        if (! $membership instanceof Membership) {
            throw new \RuntimeException('Membership was not found.');
        }

        return $membership;
    }

    private function requirePeriod(int $periodId): MembershipPeriod
    {
        $period = $this->memberships->find($periodId);

        if (! $period instanceof MembershipPeriod) {
            throw new \RuntimeException('Membership was not found.');
        }

        return $period;
    }
}
