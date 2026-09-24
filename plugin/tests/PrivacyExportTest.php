<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Privacy\PersonalDataReport;
use Foreningssystem\Application\Privacy\PrivacyExport;
use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\Participant;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use PHPUnit\Framework\TestCase;

final class PrivacyExportTest extends TestCase
{
    public function test_export_includes_one_person_and_leaves_minutes_and_other_people_out(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $roles = new MemoryBoardRoleRepository();
        $assignments = new MemoryBoardAssignmentRepository();
        $participants = new MemoryParticipantRepository();
        $meetings = new MemoryMeetingRepository();
        $audit = new MemoryAuditLog();
        $ada = $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null));
        $grace = $people->add(new Person(null, 'Grace', 'Hemlig', 'grace@example.test', PersonStatus::Known, null));
        $linked = $people->add(new Person(null, 'Kim', 'Konto', 'kim@example.test', PersonStatus::Known, 9));
        $adaId = (int) $ada->id();
        $graceId = (int) $grace->id();
        $roleId = (int) $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10))->id();
        $memberships->grant($adaId, 'M-ADA', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $memberships->grant($graceId, 'M-GRACE', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $assignments->add(new BoardAssignment(null, $adaId, $roleId, AssociationDate::fromIso('2024-01-01'), null, 'ordf@example.test', ''));
        $assignments->add(new BoardAssignment(null, $graceId, $roleId, AssociationDate::fromIso('2024-01-01'), null, 'grace-ordf@example.test', ''));
        $meeting = $meetings->add(new Meeting(null, 1, 'Styrelsemöte', MeetingMoment::fromLocal('2024-05-02 18:00'), 'Lokalen', MeetingStatus::Held));
        $participants->add(new Participant(null, (int) $meeting->id(), $adaId, Presence::Present, MeetingDuty::None));
        $participants->add(new Participant(null, (int) $meeting->id(), $graceId, Presence::Absent, MeetingDuty::None));
        $export = new PrivacyExport($people, $memberships, $assignments, $roles, $participants, $meetings, $audit, new MemoryPersonalIdentityRepository(), new MemoryGuardianRepository());

        $report = $export->collect(' Ada@Example.Test ', null, 7);
        $text = $this->text($report);

        self::assertCount(1, $report->people());
        self::assertStringContainsString('ada@example.test', $text);
        self::assertStringContainsString('M-ADA', $text);
        self::assertStringContainsString('ordf@example.test', $text);
        self::assertStringContainsString('Styrelsemöte', $text);
        self::assertStringContainsString('present', $text);
        self::assertStringNotContainsString('grace@example.test', $text);
        self::assertStringNotContainsString('M-GRACE', $text);
        self::assertStringNotContainsString('Grace', $text);
        self::assertStringNotContainsString('grace-ordf@example.test', $text);
        self::assertStringNotContainsString('HEMLIGT-PROTOKOLL', $text);
        self::assertCount(1, $audit->forObject('person', $adaId));
        self::assertSame('export_personal_data', $audit->forObject('person', $adaId)[0]->action());
        self::assertSame(7, $audit->forObject('person', $adaId)[0]->actorUserId());
        self::assertStringNotContainsString('ada@example.test', $audit->forObject('person', $adaId)[0]->action());

        $byAccount = $export->collect('nobody@example.test', 9, 7);

        self::assertCount(1, $byAccount->people());
        self::assertSame('kim@example.test', $byAccount->people()[0]->email());
        self::assertSame([], $export->collect('', null, 7)->people());
    }

    public function test_guardian_export_keeps_the_relationship_and_leaves_out_the_other_person(): void
    {
        $people = new MemoryPersonRepository();
        $child = $people->add(new Person(null, 'Lisa', 'Andersson', 'lisa@example.test', PersonStatus::Known, null));
        $guardian = $people->add(new Person(null, 'Anna', 'Andersson', 'anna@example.test', PersonStatus::Known, null));
        $guardians = new MemoryGuardianRepository();
        $guardians->addRelationship(new \Foreningssystem\Domain\Guardian\GuardianRelationship(
            null,
            (int) $child->id(),
            (int) $guardian->id(),
            'parent',
            null,
            null
        ));
        $identities = new MemoryPersonalIdentityRepository();
        $identities->save(new \Foreningssystem\Domain\Identity\PersonalIdentityRecord(
            null,
            (int) $child->id(),
            \Foreningssystem\Domain\Identity\PersonalIdentityNumber::parse('20120417-0011', \Foreningssystem\Domain\Membership\AssociationDate::fromIso('2026-09-24')),
            'Association administration',
            'Association policy',
            \Foreningssystem\Domain\Membership\AssociationDate::fromIso('2026-09-24'),
            4
        ));
        $export = new PrivacyExport(
            $people,
            new MemoryMembershipRepository(),
            new MemoryBoardAssignmentRepository(),
            new MemoryBoardRoleRepository(),
            new MemoryParticipantRepository(),
            new MemoryMeetingRepository(),
            new MemoryAuditLog(),
            $identities,
            $guardians
        );

        $childExport = $export->collect('lisa@example.test', null, 7)->people()[0];
        $guardianExport = $export->collect('anna@example.test', null, 7)->people()[0];
        $childNotes = implode("\n", $childExport->guardianNotes());
        $guardianNotes = implode("\n", $guardianExport->guardianNotes());

        self::assertSame('20120417-0011', $childExport->personalIdentityNumber());
        self::assertNull($guardianExport->personalIdentityNumber());
        self::assertStringContainsString('Guardian relationship: parent', $childNotes);
        self::assertStringNotContainsString('Anna', $childNotes);
        self::assertStringNotContainsString('anna@example.test', $childNotes);
        self::assertStringContainsString('Recorded guardian relationship exists. Relationship: parent', $guardianNotes);
        self::assertStringNotContainsString('Lisa', $guardianNotes);
        self::assertStringNotContainsString('lisa@example.test', $guardianNotes);
        self::assertStringNotContainsString('20120417-0011', $guardianNotes);
        self::assertStringNotContainsString('20120417-0011', $childNotes);
    }

    private function text(PersonalDataReport $report): string
    {
        $chunks = [];

        foreach ($report->people() as $person) {
            $chunks[] = $person->firstName() . ' ' . $person->lastName() . ' ' . $person->email();

            foreach ($person->memberships() as $membership) {
                $chunks[] = $membership->number();
            }

            foreach ($person->assignments() as $assignment) {
                $chunks[] = $assignment->roleName() . ' ' . $assignment->publicContact();
            }

            foreach ($person->attendance() as $attendance) {
                $chunks[] = $attendance->meetingTitle() . ' ' . $attendance->presence();
            }
        }

        return implode("\n", $chunks);
    }
}
