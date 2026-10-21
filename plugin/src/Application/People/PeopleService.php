<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\AuditLog;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\CoverageSpan;
use Foreningssystem\Domain\Membership\MemberCoverage;
use Foreningssystem\Domain\Membership\Membership;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipParticipant;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Membership\ParticipantAdmission;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Domain\Person\ContactDetails;
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
        private readonly MemberRegisterLock $lock,
        private readonly AuditLog $audit,
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
        ?ContactDetails $contactDetails = null,
    ): int {
        $this->require(Capabilities::EDIT_MEMBERS);
        $kind = MembershipKind::knownSlug($membershipType) ? MembershipKind::fromSlug($membershipType) : MembershipKind::Ordinary;

        if ($kind === MembershipKind::Youth && ! $birthDate instanceof AssociationDate) {
            throw new MembershipRuleException('Add a birth date before starting a youth membership.');
        }

        return $this->write(function () use ($firstName, $lastName, $email, $membershipNumber, $membershipType, $startedOn, $birthDate, $today, $contactDetails): int {
            $person = $this->people->add(new Person(
                null,
                trim($firstName),
                trim($lastName),
                trim($email),
                PersonStatus::Known,
                null,
                $this->birthDate($birthDate, $today ?? $startedOn),
                $contactDetails ?? new ContactDetails()
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
        ?ContactDetails $contactDetails = null,
    ): int {
        $this->require(Capabilities::EDIT_MEMBERS);
        $person = $this->people->add(new Person(
            null,
            trim($firstName),
            trim($lastName),
            trim($email),
            PersonStatus::Known,
            null,
            $this->birthDate($birthDate, $today ?? AssociationDate::fromIso('1970-01-01')),
            $contactDetails ?? new ContactDetails()
        ));

        return (int) $person->id();
    }

    public function addPersonToMembership(
        int $membershipId,
        string $firstName,
        string $lastName,
        string $email,
        ?AssociationDate $birthDate,
        AssociationDate $startedOn,
        ParticipantRole $role,
        bool $primary,
        ?AssociationDate $today = null,
        ?ContactDetails $contactDetails = null,
    ): int {
        $this->require(Capabilities::EDIT_MEMBERS);

        return $this->write(function () use ($membershipId, $firstName, $lastName, $email, $birthDate, $startedOn, $role, $primary, $today, $contactDetails): int {
            $membership = $this->requireAccount($membershipId);

            ParticipantAdmission::assertRole(
                $membership->kind(),
                $role,
                false,
                $this->memberships->participantsForMembership($membershipId)
            );

            $person = $this->people->add(new Person(
                null,
                trim($firstName),
                trim($lastName),
                trim($email),
                PersonStatus::Known,
                null,
                $this->birthDate($birthDate, $today ?? $startedOn),
                $contactDetails ?? new ContactDetails()
            ));
            $personId = (int) $person->id();
            $participant = new MembershipParticipant(null, $membershipId, $personId, $role, $primary, $startedOn, null);
            $this->assertNewParticipant($participant, $this->memberships->periodsForMembership($membershipId));
            $this->memberships->addParticipant($participant);

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

        $this->write(function () use ($membershipId, $personId, $role, $primary, $on): void {
            $person = $this->requirePerson($personId);
            $membership = $this->requireAccount($membershipId);

            ParticipantAdmission::assertRole(
                $membership->kind(),
                $role,
                $person->status() === PersonStatus::Deceased,
                $this->memberships->participantsForMembership($membershipId),
                $personId
            );

            $participant = new MembershipParticipant(null, (int) $membership->id(), $personId, $role, $primary, $on, null);
            $this->assertNewParticipant($participant, $this->memberships->periodsForMembership((int) $membership->id()));
            $this->memberships->addParticipant($participant);
        });
    }

    /**
     * @return list<BoardCoverageNotice>
     */
    public function endParticipation(int $membershipId, int $personId, AssociationDate $on): array
    {
        $this->require(Capabilities::EDIT_MEMBERS);

        return $this->write(function () use ($membershipId, $personId, $on): array {
            $this->requireAccount($membershipId);
            $this->requirePerson($personId);
            $open = null;

            foreach ($this->memberships->participantsForMembership($membershipId) as $participant) {
                if ($participant->personId() === $personId && ! $participant->endedOn() instanceof AssociationDate) {
                    $open = $participant;
                }
            }

            if (! $open instanceof MembershipParticipant) {
                throw new MembershipRuleException('The person has no open participation in this membership.');
            }

            $ended = $open->ended($on);
            $notices = [];

            if ($open->role()->countsAsMember()) {
                $notices = $this->preserveBoardCoverage($personId, $this->participantsReplacing($open, $ended), $this->memberships->all());
            }

            $this->memberships->saveParticipant($ended);

            return $notices;
        });
    }

    public function addPeriod(int $membershipId, AssociationDate $startedOn): int
    {
        $this->require(Capabilities::EDIT_MEMBERS);

        return $this->write(function () use ($membershipId, $startedOn): int {
            $membership = $this->requireAccount($membershipId);

            if ($membership->kind() === MembershipKind::Company) {
                return $this->addCompanyPeriod($membership, $startedOn);
            }

            $members = $this->memberParticipants($membershipId);

            if ($members === []) {
                throw new MembershipRuleException('The membership has no member to continue.');
            }

            foreach ($members as $participant) {
                if ($this->requirePerson($participant->personId())->status() === PersonStatus::Deceased) {
                    throw new MembershipRuleException('A deceased person cannot start a membership.');
                }
            }

            $period = new MembershipPeriod(null, (int) $membership->id(), MembershipStatus::Active, $startedOn, null, $membership->kind()->value);
            $this->ledger->add($this->memberships->periodsForMembership((int) $membership->id()), $period);

            foreach ($members as $participant) {
                $this->assertPersonCoverage($participant, [$period]);
            }

            $saved = $this->memberships->add($period);
            $periodId = $saved->id();

            if ($periodId === null) {
                throw new \RuntimeException('The membership was not saved.');
            }

            return $periodId;
        });
    }

    /**
     * @return list<BoardCoverageNotice>
     */
    public function endMembership(int $periodId, AssociationDate $on): array
    {
        $this->require(Capabilities::EDIT_MEMBERS);

        return $this->write(function () use ($periodId, $on): array {
            $period = $this->requirePeriod($periodId);
            $ended = $this->ledger->end($period, $on);
            $periods = $this->periodsReplacing($period, $ended);
            $notices = [];

            foreach ($this->memberParticipants($period->membershipId()) as $participant) {
                $notices = array_merge(
                    $notices,
                    $this->preserveBoardCoverage($participant->personId(), $this->memberships->allParticipants(), $periods)
                );
            }

            $this->memberships->save($ended);

            return $notices;
        });
    }

    public function updateContact(
        int $personId,
        string $firstName,
        string $lastName,
        string $email,
        ?AssociationDate $birthDate,
        AssociationDate $today,
        int $actorUserId,
        ?string $expectedVersion = null,
        ?ContactDetails $contactDetails = null,
    ): void {
        $this->require(Capabilities::EDIT_MEMBERS);
        $birthDate = $this->birthDate($birthDate, $today);

        $this->write(function () use ($personId, $firstName, $lastName, $email, $birthDate, $actorUserId, $expectedVersion, $contactDetails): void {
            $person = $this->requirePerson($personId);

            if ($expectedVersion !== null && ! hash_equals($person->contactVersion(), $expectedVersion)) {
                throw new ContactChanged('The contact details changed after the form was opened.');
            }

            $changed = $person->withContact($firstName, $lastName, $email, $birthDate, $contactDetails);
            $this->people->save($changed);

            foreach (self::contactChanges($person, $changed) as $action) {
                $this->audit->record('person', $personId, $action, $actorUserId);
            }
        });
    }

    /**
     * Names the parts of the contact details that differ, never their values. See ADR-0032.
     *
     * @return list<string>
     */
    private static function contactChanges(Person $before, Person $after): array
    {
        $actions = [];

        if ($before->firstName() !== $after->firstName() || $before->lastName() !== $after->lastName()) {
            $actions[] = 'contact_name_changed';
        }

        if ($before->email() !== $after->email()) {
            $actions[] = 'contact_email_changed';
        }

        if ($before->birthDate()?->iso() !== $after->birthDate()?->iso()) {
            $actions[] = 'contact_birth_date_changed';
        }

        if (! $before->contactDetails()->sameAddress($after->contactDetails())) {
            $actions[] = 'contact_address_changed';
        }

        if ($before->contactDetails()->phone() !== $after->contactDetails()->phone()) {
            $actions[] = 'contact_phone_changed';
        }

        return $actions;
    }

    public function markDeceased(int $personId, AssociationDate $on): void
    {
        $this->require(Capabilities::EDIT_MEMBERS);

        $this->write(function () use ($personId, $on): void {
            $person = $this->requirePerson($personId);
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

                    if (! MemberCoverage::participationOverlapsPeriod($participant, $period)) {
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

        $participant = new MembershipParticipant(null, $membershipId, $personId, $role, $primary, $startedOn, null);
        $period = new MembershipPeriod(null, $membershipId, MembershipStatus::Active, $startedOn, null, $membershipType);

        if ($role->countsAsMember()) {
            $this->assertPersonCoverage($participant, [$period]);
        }

        $this->memberships->addParticipant($participant);

        $this->ledger->add([], $period);
        $this->memberships->add($period);

        return $membership;
    }

    public function openForExistingPerson(int $personId, string $membershipNumber, string $membershipType, AssociationDate $startedOn): int
    {
        $this->require(Capabilities::EDIT_MEMBERS);

        return $this->write(function () use ($personId, $membershipNumber, $membershipType, $startedOn): int {
            $person = $this->requirePerson($personId);

            if ($person->status() === PersonStatus::Deceased) {
                throw new MembershipRuleException('A deceased person cannot start a membership.');
            }

            $kind = MembershipKind::knownSlug($membershipType) ? MembershipKind::fromSlug($membershipType) : MembershipKind::Ordinary;

            if ($kind === MembershipKind::Youth && ! $person->birthDate() instanceof AssociationDate) {
                throw new MembershipRuleException('Add a birth date before starting a youth membership.');
            }

            $membership = $this->openMembership($personId, trim($membershipNumber), trim($membershipType), $startedOn, ParticipantRole::Member, true);

            return (int) $membership->id();
        });
    }

    /**
     * @param list<MembershipPeriod> $candidatePeriods
     */
    private function assertNewParticipant(MembershipParticipant $incoming, array $candidatePeriods): void
    {
        $this->assertPersonCoverage($incoming, $candidatePeriods);
    }

    /**
     * @param list<MembershipPeriod> $candidatePeriods
     */
    private function assertPersonCoverage(MembershipParticipant $incoming, array $candidatePeriods): void
    {
        ParticipantAdmission::assertNoOverlap(
            $incoming,
            $this->memberships->participantsForMembership($incoming->membershipId()),
            $candidatePeriods,
            $this->memberships->allParticipants(),
            $this->memberships->all()
        );
    }

    /**
     * @param list<MembershipParticipant> $participants
     * @param list<MembershipPeriod> $periods
     * @return list<BoardCoverageNotice>
     */
    private function preserveBoardCoverage(int $personId, array $participants, array $periods): array
    {
        $changes = [];
        $notices = [];

        foreach ($this->openAssignments->assignmentsFor($personId) as $assignment) {
            $span = MemberCoverage::continuousCoverageFrom($personId, $assignment->startedOn(), $participants, $periods);

            if (! $span instanceof CoverageSpan) {
                if ($assignment->endedOn() instanceof AssociationDate) {
                    continue;
                }

                throw new MembershipRuleException('The membership does not cover this assignment.');
            }

            if ($span->isOpenEnded()) {
                if (! $assignment->endedOn() instanceof AssociationDate) {
                    $notices[] = new BoardCoverageNotice($personId, $assignment->roleId(), true, null);
                }

                continue;
            }

            $end = $span->endsOn();

            if (! $end instanceof AssociationDate || $end->isBefore($assignment->startedOn())) {
                throw new MembershipRuleException('The membership does not cover this assignment.');
            }

            $current = $assignment->endedOn();

            if ($current instanceof AssociationDate && ! $current->isAfter($end)) {
                continue;
            }

            $changes[] = [$assignment, $end];
        }

        foreach ($changes as [$assignment, $end]) {
            $this->openAssignments->endAssignment($assignment, $end);
            $notices[] = new BoardCoverageNotice($personId, $assignment->roleId(), false, $end->iso());
        }

        return $notices;
    }

    /**
     * @return list<MembershipParticipant>
     */
    private function participantsReplacing(MembershipParticipant $open, MembershipParticipant $ended): array
    {
        if ($open->id() === null) {
            throw new \RuntimeException('The participation was not saved.');
        }

        $rows = [];
        $replaced = false;

        foreach ($this->memberships->allParticipants() as $participant) {
            if ($participant->id() === $open->id()) {
                $rows[] = $ended;
                $replaced = true;
                continue;
            }

            $rows[] = $participant;
        }

        if (! $replaced) {
            throw new \RuntimeException('The participation was not saved.');
        }

        return $rows;
    }

    /**
     * @return list<MembershipPeriod>
     */
    private function periodsReplacing(MembershipPeriod $open, MembershipPeriod $ended): array
    {
        if ($open->id() === null) {
            throw new \RuntimeException('The membership was not saved.');
        }

        $rows = [];
        $replaced = false;

        foreach ($this->memberships->all() as $period) {
            if ($period->id() === $open->id()) {
                $rows[] = $ended;
                $replaced = true;
                continue;
            }

            $rows[] = $period;
        }

        if (! $replaced) {
            throw new \RuntimeException('The membership was not saved.');
        }

        return $rows;
    }

    public function requireKind(int $membershipId, MembershipKind $expected): void
    {
        $this->require(Capabilities::EDIT_MEMBERS);
        $membership = $this->requireAccount($membershipId);

        if ($membership->kind() === $expected) {
            return;
        }

        throw new MembershipRuleException(match ($expected) {
            MembershipKind::Family => 'This action is only available for family memberships.',
            MembershipKind::Company => 'Company contacts can only be added to company memberships.',
            default => 'That action does not match this membership.',
        });
    }

    private function addCompanyPeriod(Membership $membership, AssociationDate $startedOn): int
    {
        if ($membership->organizationId() === null || $membership->organizationId() < 1) {
            throw new MembershipRuleException('A company membership belongs to an organization.');
        }

        $period = new MembershipPeriod(null, (int) $membership->id(), MembershipStatus::Active, $startedOn, null, $membership->kind()->value);
        $this->ledger->add($this->memberships->periodsForMembership((int) $membership->id()), $period);
        $saved = $this->memberships->add($period);
        $periodId = $saved->id();

        if ($periodId === null) {
            throw new \RuntimeException('The membership was not saved.');
        }

        return $periodId;
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

    /**
     * Reads, checks and writes the register as one unit: the claim on the register is
     * taken before the transaction starts and released after it ends, so the checks see
     * every write that finished before this one.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function write(callable $work): mixed
    {
        return $this->lock->hold(fn (): mixed => $this->transaction->run($work));
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
