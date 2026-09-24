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

            foreach ($this->memberships->all() as $existing) {
                if ($existing->number() === $number) {
                    throw new \Foreningssystem\Domain\Membership\MembershipRuleException('Membership number is already used.');
                }
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
            $this->ledger->add($this->memberships->all(), $period);
            $this->memberships->add($period);

            return $personId;
        });
    }

    public function endMembership(int $membershipId, AssociationDate $on): void
    {
        $this->require(Capabilities::EDIT_MEMBERS);
        $period = $this->requireMembership($membershipId);

        $this->transaction->run(function () use ($period, $on): void {
            $this->memberships->save($this->ledger->end($period, $on));
        });
    }

    public function markDeceased(int $personId, AssociationDate $on): void
    {
        $this->require(Capabilities::EDIT_MEMBERS);
        $person = $this->requirePerson($personId);

        $this->transaction->run(function () use ($person, $on): void {
            $this->people->save($person->markedDeceased());

            foreach ($this->ledger->endOpenPeriods($this->periodsFor($person), $on) as $period) {
                if ($period->status() === MembershipStatus::Ended) {
                    $this->memberships->save($period);
                }
            }
        });
    }

    public function activeMemberCount(): int
    {
        $this->require(Capabilities::VIEW_MEMBERS);
        $people = [];

        foreach ($this->memberships->all() as $period) {
            if ($period->countsAsActiveMember()) {
                $people[$period->personId()] = true;
            }
        }

        return count($people);
    }

    /**
     * @return list<PersonRecord>
     */
    public function listPeople(): array
    {
        $this->require(Capabilities::VIEW_MEMBERS);
        $records = [];

        foreach ($this->people->all() as $person) {
            $personId = $person->id();

            if ($personId === null) {
                continue;
            }

            $records[] = new PersonRecord($person, $this->latestPeriod($this->periodsFor($person)));
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
        $periods = [];

        foreach ($this->memberships->all() as $period) {
            if ($period->personId() === $personId) {
                $periods[] = $period;
            }
        }

        return $periods;
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
