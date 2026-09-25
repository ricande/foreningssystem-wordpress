<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Account;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\Age;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MemberCoverage;
use Foreningssystem\Domain\Membership\MembershipParticipant;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;
use Foreningssystem\Domain\Person\PersonStatus;
use InvalidArgumentException;

final class MemberAccountProvisioning implements MemberAccountProvisioner
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly MembershipRepository $memberships,
        private readonly WordPressAccountGateway $accounts,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function provision(int $personId, AssociationDate $on): ProvisioningResult
    {
        $person = $this->people->find($personId);

        if (! $person instanceof Person) {
            return new ProvisioningResult(AccountOutcome::Failed, $personId);
        }

        $people = $this->people->all();

        return $this->provisionLoaded(
            $person,
            $on,
            $this->emailCounts($people),
            $this->memberships->allParticipants(),
            $this->memberships->all()
        );
    }

    /**
     * @return list<ProvisioningResult>
     */
    public function reconcile(AssociationDate $on): array
    {
        $people = $this->people->all();
        $counts = $this->emailCounts($people);
        $participants = $this->memberships->allParticipants();
        $periods = $this->memberships->all();
        $results = [];

        foreach ($people as $person) {
            $personId = $person->id();

            if ($personId === null) {
                continue;
            }

            try {
                $results[] = $this->provisionLoaded($person, $on, $counts, $participants, $periods);
            } catch (\Throwable) {
                $results[] = new ProvisioningResult(AccountOutcome::Failed, $personId);
            }
        }

        return $results;
    }

    public function status(int $personId, AssociationDate $on): MemberAccountStatus
    {
        $person = $this->people->find($personId);

        if (! $person instanceof Person) {
            return new MemberAccountStatus(AccountOutcome::Failed, '', null, null, null, false);
        }

        $outcome = $this->evaluate($person, $on, $this->emailCounts($this->people->all()), $this->memberships->allParticipants(), $this->memberships->all());
        $userId = $person->wordpressUserId();
        $accountName = null;
        $accountEmail = null;
        $mismatch = false;

        if ($outcome === AccountOutcome::AlreadyLinked && $userId !== null && $this->accounts->userExists($userId)) {
            $accountName = $this->accounts->displayName($userId);
            $accountEmail = $this->accounts->email($userId);
            $mismatch = $this->emailsDiffer($person->email(), $accountEmail);
        }

        if ($outcome === AccountOutcome::WordpressEmailConflict) {
            $conflict = $this->accounts->findUserIdByEmail($person->email());

            if ($conflict !== null && $this->accounts->userExists($conflict)) {
                $userId = $conflict;
                $accountName = $this->accounts->displayName($conflict);
                $accountEmail = $this->accounts->email($conflict);
            }
        }

        return new MemberAccountStatus($outcome, $person->email(), $accountName, $accountEmail, $userId, $mismatch);
    }

    public function linkExisting(int $personId, int $userId, AssociationDate $on): ProvisioningResult
    {
        $this->requireEdit();
        $person = $this->people->find($personId);

        if (! $person instanceof Person || $person->id() === null) {
            return new ProvisioningResult(AccountOutcome::Failed, $personId);
        }

        if ($userId < 1 || ! $this->accounts->userExists($userId)) {
            return new ProvisioningResult(AccountOutcome::UserNotFound, $personId);
        }

        if ($person->wordpressUserId() === $userId) {
            return new ProvisioningResult(AccountOutcome::AlreadyLinked, $personId, $userId);
        }

        if ($person->wordpressUserId() !== null) {
            return new ProvisioningResult(AccountOutcome::UnlinkFirst, $personId, $person->wordpressUserId());
        }

        foreach ($this->people->all() as $other) {
            if ($other->id() !== $personId && $other->wordpressUserId() === $userId) {
                return new ProvisioningResult(AccountOutcome::UserTaken, $personId, $userId);
            }
        }

        if ($this->isKnownMinor($person, $on)) {
            return new ProvisioningResult(AccountOutcome::MinorLinkRefused, $personId);
        }

        $this->people->save($person->linkedToWordpressUser($userId));

        return new ProvisioningResult(AccountOutcome::Linked, $personId, $userId);
    }

    public function unlink(int $personId): ProvisioningResult
    {
        $this->requireEdit();
        $person = $this->people->find($personId);

        if (! $person instanceof Person || $person->id() === null) {
            return new ProvisioningResult(AccountOutcome::Failed, $personId);
        }

        $previous = $person->wordpressUserId();
        $this->people->save($person->withoutWordpressUser());

        return new ProvisioningResult(AccountOutcome::Unlinked, $personId, $previous);
    }

    public function clearMissingLink(int $personId): ProvisioningResult
    {
        $this->requireEdit();
        $person = $this->people->find($personId);

        if (! $person instanceof Person || $person->id() === null) {
            return new ProvisioningResult(AccountOutcome::Failed, $personId);
        }

        $userId = $person->wordpressUserId();

        if ($userId === null) {
            return new ProvisioningResult(AccountOutcome::Failed, $personId);
        }

        if ($this->accounts->userExists($userId)) {
            return new ProvisioningResult(AccountOutcome::LinkStillPresent, $personId, $userId);
        }

        $this->people->save($person->withoutWordpressUser());

        return new ProvisioningResult(AccountOutcome::BrokenLinkCleared, $personId);
    }

    /**
     * @param array<string, int> $emailCounts
     * @param list<MembershipParticipant> $participants
     * @param list<MembershipPeriod> $periods
     */
    private function provisionLoaded(
        Person $person,
        AssociationDate $on,
        array $emailCounts,
        array $participants,
        array $periods,
    ): ProvisioningResult {
        $personId = $person->id();

        if ($personId === null) {
            return new ProvisioningResult(AccountOutcome::Failed, null);
        }

        $outcome = $this->evaluate($person, $on, $emailCounts, $participants, $periods);

        if ($outcome !== AccountOutcome::Eligible) {
            $reportedUserId = $outcome === AccountOutcome::AlreadyLinked || $outcome === AccountOutcome::MissingWordpressUser
                ? $person->wordpressUserId()
                : null;

            return new ProvisioningResult($outcome, $personId, $reportedUserId);
        }

        $login = $this->loginFor($personId);

        if ($this->accounts->findUserIdByLogin($login) !== null) {
            return new ProvisioningResult(AccountOutcome::Failed, $personId);
        }

        try {
            $userId = $this->accounts->createSubscriber($login, $person->email(), trim($person->firstName() . ' ' . $person->lastName()));
        } catch (\Throwable) {
            return new ProvisioningResult(AccountOutcome::Failed, $personId);
        }

        try {
            $this->people->save($person->linkedToWordpressUser($userId));
        } catch (\Throwable) {
            $this->accounts->deleteCreatedUser($userId);

            return new ProvisioningResult(AccountOutcome::Failed, $personId);
        }

        try {
            $this->accounts->notifyNewUser($userId);
        } catch (\Throwable) {
            // The Person link is the canonical account. WordPress lost-password can still recover access.
        }

        return new ProvisioningResult(AccountOutcome::Created, $personId, $userId);
    }

    /**
     * @param array<string, int> $emailCounts
     * @param list<MembershipParticipant> $participants
     * @param list<MembershipPeriod> $periods
     */
    private function evaluate(
        Person $person,
        AssociationDate $on,
        array $emailCounts,
        array $participants,
        array $periods,
    ): AccountOutcome {
        $linkedUserId = $person->wordpressUserId();

        if ($linkedUserId !== null) {
            return $this->accounts->userExists($linkedUserId)
                ? AccountOutcome::AlreadyLinked
                : AccountOutcome::MissingWordpressUser;
        }

        if ($person->status() === PersonStatus::Deceased) {
            return AccountOutcome::Deceased;
        }

        if ($this->normalize($person->email()) === '') {
            return AccountOutcome::NoEmail;
        }

        if ($this->isKnownMinor($person, $on)) {
            return AccountOutcome::KnownMinor;
        }

        $personId = $person->id();

        if ($personId === null || ! MemberCoverage::isActiveMember($personId, $on, $participants, $periods)) {
            return AccountOutcome::NotActiveMember;
        }

        $email = $this->normalize($person->email());

        if (($emailCounts[$email] ?? 0) > 1) {
            return AccountOutcome::SharedPersonEmail;
        }

        if ($this->accounts->findUserIdByEmail($person->email()) !== null) {
            return AccountOutcome::WordpressEmailConflict;
        }

        return AccountOutcome::Eligible;
    }

    private function isKnownMinor(Person $person, AssociationDate $on): bool
    {
        $birth = $person->birthDate();

        if (! $birth instanceof AssociationDate) {
            return false;
        }

        try {
            return Age::years($birth, $on) < 18;
        } catch (InvalidArgumentException) {
            return true;
        }
    }

    /**
     * @param list<Person> $people
     * @return array<string, int>
     */
    private function emailCounts(array $people): array
    {
        $counts = [];

        foreach ($people as $person) {
            $email = $this->normalize($person->email());

            if ($email === '') {
                continue;
            }

            $counts[$email] = ($counts[$email] ?? 0) + 1;
        }

        return $counts;
    }

    private function emailsDiffer(string $left, string $right): bool
    {
        $normalizedLeft = $this->normalize($left);
        $normalizedRight = $this->normalize($right);

        return $normalizedLeft !== '' && $normalizedRight !== '' && $normalizedLeft !== $normalizedRight;
    }

    private function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    private function loginFor(int $personId): string
    {
        return 'assoc-member-' . $personId;
    }

    private function requireEdit(): void
    {
        if (! $this->authorizer->allows(Capabilities::EDIT_MEMBERS)) {
            throw new NotAllowed(Capabilities::EDIT_MEMBERS);
        }
    }
}
