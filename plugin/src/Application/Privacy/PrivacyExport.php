<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Privacy;

use Foreningssystem\Domain\Board\BoardAssignmentRepository;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Board\BoardRoleRepository;
use Foreningssystem\Domain\Guardian\GuardianRepository;
use Foreningssystem\Domain\Identity\PersonalIdentityRepository;
use Foreningssystem\Domain\Meeting\AuditLog;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingRepository;
use Foreningssystem\Domain\Meeting\ParticipantRepository;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;

final class PrivacyExport
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly MembershipRepository $memberships,
        private readonly BoardAssignmentRepository $assignments,
        private readonly BoardRoleRepository $roles,
        private readonly ParticipantRepository $participants,
        private readonly MeetingRepository $meetings,
        private readonly AuditLog $audit,
        private readonly PersonalIdentityRepository $identities,
        private readonly GuardianRepository $guardians,
    ) {
    }

    public function collect(string $email, ?int $linkedUserId, int $actorUserId): PersonalDataReport
    {
        $person = PrivacySubject::resolve($this->people, $email, $linkedUserId);
        $id = $person instanceof Person ? $person->id() : null;

        if (! $person instanceof Person || $id === null) {
            return new PersonalDataReport([]);
        }

        $exported = new ExportedPerson(
            $id,
            $person->firstName(),
            $person->lastName(),
            $person->email(),
            $person->status()->value,
            $this->membershipsFor($id),
            $this->assignmentsFor($id),
            $this->attendanceFor($id),
            $person->birthDate()?->iso(),
            $this->identityFor($id, $actorUserId),
            $this->guardianNotes($id)
        );

        if ($actorUserId >= 1) {
            $this->audit->record('person', $id, 'export_personal_data', $actorUserId);
        }

        return new PersonalDataReport([$exported]);
    }

    /**
     * @return list<ExportedMembership>
     */
    private function membershipsFor(int $personId): array
    {
        $accounts = [];

        foreach ($this->memberships->allMemberships() as $membership) {
            if ($membership->id() !== null) {
                $accounts[$membership->id()] = $membership;
            }
        }

        $rows = [];

        foreach ($this->memberships->allParticipants() as $participant) {
            if ($participant->personId() !== $personId) {
                continue;
            }

            $account = $accounts[$participant->membershipId()] ?? null;

            if ($account === null) {
                continue;
            }

            foreach ($this->memberships->periodsForMembership($participant->membershipId()) as $period) {
                $id = $period->id();

                if ($id === null || isset($rows[$id]) || ! \Foreningssystem\Domain\Membership\MemberCoverage::participationOverlapsPeriod($participant, $period)) {
                    continue;
                }

                $rows[$id] = new ExportedMembership(
                    $id,
                    $account->number(),
                    $period->historicalClass() !== '' ? $period->historicalClass() : $account->kind()->value,
                    $period->status()->value,
                    $period->startedOn()->iso(),
                    $period->endedOn()?->iso()
                );
            }
        }

        return array_values($rows);
    }

    private function identityFor(int $personId, int $actorUserId): ?string
    {
        $record = $this->identities->findForPerson($personId);

        if ($record === null) {
            return null;
        }

        if ($actorUserId >= 1 && $record->id() !== null) {
            $this->audit->record('personal_identity', $record->id(), 'export_personal_identity', $actorUserId);
        }

        return $record->number()->canonical();
    }

    /**
     * @return list<string>
     */
    private function guardianNotes(int $personId): array
    {
        $notes = [];

        foreach ($this->guardians->relationshipsForChild($personId) as $relationship) {
            $notes[] = 'Guardian relationship: ' . $relationship->relationship();
        }

        foreach ($this->guardians->relationshipsForGuardian($personId) as $relationship) {
            $notes[] = 'Recorded guardian relationship exists. Relationship: ' . $relationship->relationship();
        }

        foreach ($this->guardians->approvalsForChild($personId) as $approval) {
            $notes[] = 'Guardian approval: ' . $approval->purpose() . ' / ' . $approval->method() . ' / ' . $approval->approvedAt()->format('Y-m-d');
        }

        foreach ($this->guardians->approvalsForGuardian($personId) as $approval) {
            $notes[] = 'Guardian approval given: ' . $approval->purpose() . ' / ' . $approval->method() . ' / ' . $approval->approvedAt()->format('Y-m-d');
        }

        return $notes;
    }

    /**
     * @return list<ExportedAssignment>
     */
    private function assignmentsFor(int $personId): array
    {
        $rows = [];

        foreach ($this->assignments->all() as $assignment) {
            $id = $assignment->id();
            $role = $this->roles->find($assignment->roleId());

            if ($assignment->personId() !== $personId || $id === null || ! $role instanceof BoardRole) {
                continue;
            }

            $rows[] = new ExportedAssignment(
                $id,
                $role->name(),
                $assignment->startedOn()->iso(),
                $assignment->endedOn()?->iso(),
                $assignment->publicContact()
            );
        }

        return $rows;
    }

    /**
     * @return list<ExportedAttendance>
     */
    private function attendanceFor(int $personId): array
    {
        $rows = [];

        foreach ($this->participants->forPerson($personId) as $participant) {
            $meeting = $this->meetings->find($participant->meetingId());
            $id = $participant->id();

            if (! $meeting instanceof Meeting || $id === null) {
                continue;
            }

            $rows[] = new ExportedAttendance(
                $id,
                $meeting->title(),
                $meeting->startsAt()->date(),
                $participant->presence()->value,
                $participant->duty()->value
            );
        }

        return $rows;
    }
}
