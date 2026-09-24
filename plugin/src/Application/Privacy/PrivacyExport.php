<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Privacy;

use Foreningssystem\Domain\Board\BoardAssignmentRepository;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Board\BoardRoleRepository;
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
    ) {
    }

    public function collect(string $email, ?int $linkedUserId, int $actorUserId): PersonalDataReport
    {
        $people = [];

        foreach ($this->matches($email, $linkedUserId) as $person) {
            $id = $person->id();

            if ($id === null) {
                continue;
            }

            $people[] = new ExportedPerson(
                $id,
                $person->firstName(),
                $person->lastName(),
                $person->email(),
                $person->status()->value,
                $this->membershipsFor($id),
                $this->assignmentsFor($id),
                $this->attendanceFor($id)
            );

            if ($actorUserId >= 1) {
                $this->audit->record('person', $id, 'export_personal_data', $actorUserId);
            }
        }

        return new PersonalDataReport($people);
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
     * @return list<ExportedMembership>
     */
    private function membershipsFor(int $personId): array
    {
        $rows = [];

        foreach ($this->memberships->all() as $period) {
            $id = $period->id();

            if ($period->personId() !== $personId || $id === null) {
                continue;
            }

            $rows[] = new ExportedMembership(
                $id,
                $period->number(),
                $period->type(),
                $period->status()->value,
                $period->startedOn()->iso(),
                $period->endedOn()?->iso()
            );
        }

        return $rows;
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
