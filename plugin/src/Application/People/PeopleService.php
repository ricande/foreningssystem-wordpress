<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;
use Foreningssystem\Domain\Person\PersonStatus;

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
    ): int {
        $this->require(Capabilities::EDIT_MEMBERS);

        return $this->transaction->run(function () use ($firstName, $lastName, $email, $membershipNumber, $membershipType, $startedOn): int {
            $number = trim($membershipNumber);

            if ($this->memberships->findByNumber($number) instanceof MembershipPeriod) {
                throw new \Foreningssystem\Domain\Membership\MembershipRuleException('Membership number is already used.');
            }

            $person = $this->people->add(new Person(
                null,
                trim($firstName),
                trim($lastName),
                trim($email),
                PersonStatus::Known,
                null
            ));
            $personId = $person->id();

            if ($personId === null) {
                throw new \RuntimeException('The person was not saved.');
            }

            $period = new MembershipPeriod(
                null,
                $personId,
                $number,
                trim($membershipType),
                MembershipStatus::Active,
                $startedOn,
                null
            );
            $this->ledger->add($this->memberships->forPerson($personId), $period);
            $this->memberships->add($period);

            return $personId;
        });
    }

    public function addMembership(int $personId, string $membershipNumber, string $membershipType, AssociationDate $startedOn): int
    {
        $this->require(Capabilities::EDIT_MEMBERS);
        $person = $this->requirePerson($personId);

        if ($person->status() === PersonStatus::Deceased) {
            throw new \Foreningssystem\Domain\Membership\MembershipRuleException('A deceased person cannot start a membership.');
        }

        return $this->transaction->run(function () use ($personId, $membershipNumber, $membershipType, $startedOn): int {
            $period = new MembershipPeriod(
                null,
                $personId,
                trim($membershipNumber),
                trim($membershipType),
                MembershipStatus::Active,
                $startedOn,
                null
            );
            if ($this->memberships->findByNumber(trim($membershipNumber)) instanceof MembershipPeriod) {
                throw new \Foreningssystem\Domain\Membership\MembershipRuleException('Membership number is already used.');
            }

            $this->ledger->add($this->memberships->forPerson($personId), $period);
            $saved = $this->memberships->add($period);
            $membershipId = $saved->id();

            if ($membershipId === null) {
                throw new \RuntimeException('The membership was not saved.');
            }

            return $membershipId;
        });
    }

    public function endMembership(int $membershipId, AssociationDate $on): void
    {
        $this->require(Capabilities::EDIT_MEMBERS);
        $period = $this->requireMembership($membershipId);

        $this->transaction->run(function () use ($period, $on): void {
            $this->openAssignments->endOpen($period->personId(), $on);
            $this->memberships->save($this->ledger->end($period, $on));
        });
    }

    public function markDeceased(int $personId, AssociationDate $on): void
    {
        $this->require(Capabilities::EDIT_MEMBERS);
        $person = $this->requirePerson($personId);

        $this->transaction->run(function () use ($person, $personId, $on): void {
            $this->openAssignments->endOpen($personId, $on);

            foreach ($this->ledger->endOpenPeriods($this->periodsFor($person), $on) as $period) {
                if ($period->status() === MembershipStatus::Ended) {
                    $this->memberships->save($period);
                }
            }

            $this->people->save($person->markedDeceased());
        });
    }

    public function activeMemberCount(AssociationDate $on): int
    {
        $this->require(Capabilities::VIEW_MEMBERS);

        return $this->countActiveMembers($on);
    }

    public function publicMemberCount(AssociationDate $on): int
    {
        return $this->countActiveMembers($on);
    }

    /**
     * @return list<PersonRecord>
     */
    public function listPeople(): array
    {
        $this->require(Capabilities::VIEW_MEMBERS);
        $byPerson = [];

        foreach ($this->memberships->all() as $period) {
            $byPerson[$period->personId()][] = $period;
        }

        $records = [];

        foreach ($this->people->all() as $person) {
            $personId = $person->id();

            if ($personId === null) {
                continue;
            }

            $records[] = new PersonRecord($person, $this->latestPeriod($byPerson[$personId] ?? []));
        }

        return $records;
    }

    /**
     * @param list<MembershipPeriod> $periods
     */
    private function latestPeriod(array $periods): ?MembershipPeriod
    {
        $latest = null;

        foreach ($periods as $period) {
            if (! $latest instanceof MembershipPeriod || $period->startedOn()->isAfter($latest->startedOn())) {
                $latest = $period;
            }
        }

        return $latest;
    }

    /**
     * @return list<MembershipPeriod>
     */
    private function periodsFor(Person $person): array
    {
        $personId = $person->id();

        return $personId === null ? [] : $this->memberships->forPerson($personId);
    }

    private function countActiveMembers(AssociationDate $on): int
    {
        $people = [];

        foreach ($this->memberships->all() as $period) {
            if ($period->isActiveOn($on)) {
                $people[$period->personId()] = true;
            }
        }

        return count($people);
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

    private function requireMembership(int $membershipId): MembershipPeriod
    {
        $period = $this->memberships->find($membershipId);

        if (! $period instanceof MembershipPeriod) {
            throw new \RuntimeException('Membership was not found.');
        }

        return $period;
    }
}
