<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Privacy;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardAssignmentRepository;
use Foreningssystem\Domain\Meeting\AuditLog;
use Foreningssystem\Domain\Meeting\MeetingRepository;
use Foreningssystem\Domain\Meeting\MinutesRepository;
use Foreningssystem\Domain\Meeting\ParticipantRepository;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Domain\Meeting\SignedCopyRepository;
use Foreningssystem\Domain\Identity\PersonalIdentityRepository;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;

final class PrivacyErase
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly MembershipRepository $memberships,
        private readonly BoardAssignmentRepository $assignments,
        private readonly ParticipantRepository $participants,
        private readonly MeetingRepository $meetings,
        private readonly MinutesRepository $minutes,
        private readonly SignedCopyRepository $copies,
        private readonly AuditLog $audit,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
        private readonly PersonalIdentityRepository $identities,
    ) {
    }

    /**
     * @return list<EraseOutcome>
     */
    public function erase(string $email, ?int $linkedUserId, int $actorUserId): array
    {
        if (! $this->authorizer->allows(Capabilities::ERASE_MEMBER_DATA)) {
            throw new NotAllowed(Capabilities::ERASE_MEMBER_DATA);
        }

        $outcomes = [];

        foreach ($this->matches($email, $linkedUserId) as $person) {
            $outcomes[] = $this->erasePerson($person, $actorUserId);
        }

        return $outcomes;
    }

    private function erasePerson(Person $person, int $actorUserId): EraseOutcome
    {
        $id = (int) $person->id();
        $anonymized = $person->anonymized();
        $identifiersCleared = $person->firstName() !== $anonymized->firstName()
            || $person->lastName() !== $anonymized->lastName()
            || $person->email() !== $anonymized->email()
            || $person->wordpressUserId() !== null;
        $assignments = $this->assignmentsFor($id);
        $publicContactCleared = false;

        foreach ($assignments as $assignment) {
            if ($assignment->publicContact() !== '') {
                $publicContactCleared = true;
            }
        }

        [$minutesNameRetained, $signedCopyRetained] = $this->retainedRecords($person);
        $membershipRetained = $this->membershipsFor($id) !== [];
        $assignmentRetained = $assignments !== [];

        $this->transaction->run(function () use ($anonymized, $assignments, $id, $actorUserId, $identifiersCleared, $publicContactCleared): void {
            $this->people->save($anonymized);
            $identity = $this->identities->findForPerson($id);

            if ($identity !== null && $identity->id() !== null) {
                $this->identities->remove($id);
                $this->audit->record('personal_identity', $identity->id(), 'identity_removed', $actorUserId);
            }

            foreach ($assignments as $assignment) {
                if ($assignment->publicContact() !== '') {
                    $this->assignments->save($assignment->withoutPublicContact());
                }
            }

            if ($actorUserId >= 1 && ($identifiersCleared || $publicContactCleared)) {
                $this->audit->record('person', $id, 'anonymize_person', $actorUserId);
            }
        });

        return new EraseOutcome(
            $id,
            $identifiersCleared,
            $publicContactCleared,
            $membershipRetained,
            $assignmentRetained,
            $minutesNameRetained,
            $signedCopyRetained
        );
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function retainedRecords(Person $person): array
    {
        $id = (int) $person->id();
        $name = $person->firstName() . ' ' . $person->lastName();
        $minutesNameRetained = false;
        $signedCopyRetained = false;
        $attended = [];

        foreach ($this->participants->forPerson($id) as $participant) {
            $attended[$participant->meetingId()] = true;
        }

        foreach ($this->meetings->all() as $meeting) {
            $meetingId = $meeting->id();

            if ($meetingId === null) {
                continue;
            }

            foreach ($this->minutes->forMeeting($meetingId) as $revision) {
                $revisionId = $revision->id();

                if ($revision->state() !== RevisionState::Finalized || $revisionId === null) {
                    continue;
                }

                $named = $name !== ' ' && str_contains($revision->body(), $name);

                if ($named) {
                    $minutesNameRetained = true;
                }

                if (($named || isset($attended[$meetingId])) && $this->copies->currentForRevision($revisionId) !== null) {
                    $signedCopyRetained = true;
                }
            }
        }

        return [$minutesNameRetained, $signedCopyRetained];
    }

    /**
     * @return list<Person>
     */
    private function matches(string $email, ?int $linkedUserId): array
    {
        $needle = trim($email);
        $userId = $linkedUserId !== null && $linkedUserId >= 1 ? $linkedUserId : null;
        $found = [];

        foreach ($this->people->all() as $person) {
            $id = $person->id();

            if ($id === null || isset($found[$id])) {
                continue;
            }

            $byEmail = $needle !== '' && strcasecmp($person->email(), $needle) === 0;
            $byUser = $userId !== null && $person->wordpressUserId() === $userId;

            if ($byEmail || $byUser) {
                $found[$id] = $person;
            }
        }

        return array_values($found);
    }

    /**
     * @return list<MembershipPeriod>
     */
    private function membershipsFor(int $personId): array
    {
        $rows = [];

        foreach ($this->memberships->allParticipants() as $participant) {
            if ($participant->personId() !== $personId) {
                continue;
            }

            foreach ($this->memberships->periodsForMembership($participant->membershipId()) as $period) {
                if ($period->id() !== null) {
                    $rows[] = $period;
                }
            }
        }

        return $rows;
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
