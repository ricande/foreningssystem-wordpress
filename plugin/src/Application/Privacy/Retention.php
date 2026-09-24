<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Privacy;

use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardAssignmentRepository;
use Foreningssystem\Domain\Identity\PersonalIdentityRepository;
use Foreningssystem\Domain\Meeting\AuditLog;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MemberCoverage;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;
use Foreningssystem\Domain\Privacy\RetentionPeriod;

final class Retention
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly MembershipRepository $memberships,
        private readonly BoardAssignmentRepository $assignments,
        private readonly AuditLog $audit,
        private readonly Transaction $transaction,
        private readonly PersonalIdentityRepository $identities,
    ) {
    }

    public function apply(AssociationDate $today, RetentionPeriod $period, int $actorUserId): RetentionResult
    {
        $due = [];

        foreach ($this->people->all() as $person) {
            if ($person->id() !== null && $this->isDue($person, $today, $period->years())) {
                $due[] = $person;
            }
        }

        $removed = 0;
        $anonymized = 0;

        $this->transaction->run(function () use ($today, $period, $due, $actorUserId, &$removed, &$anonymized): void {
            $removed = $this->audit->forgetOnOrBefore($today->minusYears($period->years())->iso());

            foreach ($due as $person) {
                $this->anonymize($person, $actorUserId);
                $anonymized++;
            }
        });

        return new RetentionResult($anonymized, $removed);
    }

    private function isDue(Person $person, AssociationDate $today, int $years): bool
    {
        $id = (int) $person->id();
        $latestEnd = MemberCoverage::retentionEnd($id, $this->memberships->allParticipants(), $this->memberships->all());

        if (! $latestEnd instanceof AssociationDate || $today->isBefore($latestEnd->plusYears($years))) {
            return false;
        }

        return ! $this->alreadyClear($person);
    }

    private function alreadyClear(Person $person): bool
    {
        $clear = $person->anonymized();

        if (
            $person->firstName() !== $clear->firstName()
            || $person->lastName() !== $clear->lastName()
            || $person->email() !== ''
            || $person->wordpressUserId() !== null
            || $person->birthDate() !== null
            || $this->identities->findForPerson((int) $person->id()) !== null
        ) {
            return false;
        }

        foreach ($this->assignmentsFor((int) $person->id()) as $assignment) {
            if ($assignment->publicContact() !== '') {
                return false;
            }
        }

        return true;
    }

    private function anonymize(Person $person, int $actorUserId): void
    {
        $id = (int) $person->id();
        $this->people->save($person->anonymized());
        $identity = $this->identities->findForPerson($id);

        if ($identity !== null && $identity->id() !== null) {
            $this->identities->remove($id);
            $this->audit->record('personal_identity', $identity->id(), 'identity_removed', $actorUserId);
        }

        foreach ($this->assignmentsFor($id) as $assignment) {
            if ($assignment->publicContact() !== '') {
                $this->assignments->save($assignment->withoutPublicContact());
            }
        }

        $this->audit->record('person', $id, 'anonymize_person', $actorUserId);
    }

    /**
     * @return list<BoardAssignment>
     */
    private function assignmentsFor(int $personId): array
    {
        $rows = [];

        foreach ($this->assignments->all() as $assignment) {
            if ($assignment->personId() === $personId && $assignment->id() !== null) {
                $rows[] = $assignment;
            }
        }

        return $rows;
    }
}
