<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Application\Privacy\PrivacyErase;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\Participant;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Domain\Meeting\SignedCopyType;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use PHPUnit\Framework\TestCase;

final class PrivacyEraseTest extends TestCase
{
    public function test_erase_clears_contact_details_and_keeps_governance_records(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $assignments = new MemoryBoardAssignmentRepository();
        $participants = new MemoryParticipantRepository();
        $meetings = new MemoryMeetingRepository();
        $minutes = new MemoryMinutesRepository();
        $copies = new MemorySignedCopyRepository();
        $audit = new MemoryAuditLog();
        $ada = $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, 4));
        $grace = $people->add(new Person(null, 'Grace', 'Hemlig', 'grace@example.test', PersonStatus::Known, null));
        $adaId = (int) $ada->id();
        $graceId = (int) $grace->id();
        $roleId = (int) (new MemoryBoardRoleRepository())->add(new BoardRole(null, 'chair', 'Ordförande', false, 10))->id();
        $memberships->add(new MembershipPeriod(null, $adaId, 'M-ADA', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null));
        $memberships->add(new MembershipPeriod(null, $graceId, 'M-GRACE', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null));
        $adaAssignment = $assignments->add(new BoardAssignment(null, $adaId, $roleId, AssociationDate::fromIso('2024-01-01'), null, 'ada@example.test', '2024'));
        $graceAssignment = $assignments->add(new BoardAssignment(null, $graceId, $roleId, AssociationDate::fromIso('2024-02-01'), null, 'grace-ordf@example.test', ''));
        $meeting = $meetings->add(new Meeting(null, 1, 'Styrelsemöte', MeetingMoment::fromLocal('2024-05-02 18:00'), 'Lokalen', MeetingStatus::Held));
        $meetingId = (int) $meeting->id();
        $participants->add(new Participant(null, $meetingId, $adaId, Presence::Present, MeetingDuty::None));
        $lockedBody = 'Ada Lovelace närvarade. HEMLIGT-PROTOKOLL';
        $draftBody = 'Utkast nämner Ada Lovelace';
        $locked = $minutes->addRevision(new MinutesRevision(null, 1, $meetingId, 1, RevisionState::Finalized, $lockedBody, '{"source":"locked"}', false));
        $draft = $minutes->addRevision(new MinutesRevision(null, 1, $meetingId, 2, RevisionState::Draft, $draftBody, '{"source":"draft"}', false));
        $copy = $copies->add((int) $locked->id(), SignedCopyType::PDF, 'signed-' . (int) $locked->id() . '-' . str_repeat('ab', 32) . '.pdf');
        $erase = $this->eraser($people, $memberships, $assignments, $participants, $meetings, $minutes, $copies, $audit, true);

        $outcomes = $erase->erase(' Ada@Example.Test ', null, 7);
        $savedAda = $people->find($adaId);
        $savedGrace = $people->find($graceId);
        $savedAssignment = $assignments->find((int) $adaAssignment->id());
        $savedGraceAssignment = $assignments->find((int) $graceAssignment->id());
        $savedLocked = $minutes->findRevision((int) $locked->id());
        $savedDraft = $minutes->findRevision((int) $draft->id());
        $savedCopy = $copies->find((int) $copy->id());
        $events = $audit->forObject('person', $adaId);

        self::assertCount(1, $outcomes);
        self::assertTrue($outcomes[0]->identifiersCleared());
        self::assertTrue($outcomes[0]->publicContactCleared());
        self::assertTrue($outcomes[0]->membershipRetained());
        self::assertTrue($outcomes[0]->assignmentRetained());
        self::assertTrue($outcomes[0]->minutesNameRetained());
        self::assertTrue($outcomes[0]->signedCopyRetained());
        self::assertNotNull($savedAda);
        self::assertSame(Person::ANONYMOUS_FIRST_NAME, $savedAda->firstName());
        self::assertSame(Person::ANONYMOUS_LAST_NAME, $savedAda->lastName());
        self::assertSame('', $savedAda->email());
        self::assertNull($savedAda->wordpressUserId());
        self::assertSame(PersonStatus::Known, $savedAda->status());
        $adaPeriod = null;

        foreach ($memberships->all() as $period) {
            if ($period->personId() === $adaId) {
                $adaPeriod = $period;
            }
        }

        self::assertNotNull($adaPeriod);
        self::assertSame('M-ADA', $adaPeriod->number());
        self::assertSame(MembershipStatus::Active, $adaPeriod->status());
        self::assertSame('2024-01-01', $adaPeriod->startedOn()->iso());
        self::assertNotNull($savedAssignment);
        self::assertSame('', $savedAssignment->publicContact());
        self::assertSame('2024-01-01', $savedAssignment->startedOn()->iso());
        self::assertSame('2024', $savedAssignment->termLabel());
        self::assertSame($roleId, $savedAssignment->roleId());
        self::assertNotNull($savedLocked);
        self::assertSame($lockedBody, $savedLocked->body());
        self::assertNotNull($savedDraft);
        self::assertSame($draftBody, $savedDraft->body());
        self::assertNotNull($savedCopy);
        self::assertSame($copy->storageName(), $savedCopy->storageName());
        self::assertNotNull($savedGrace);
        self::assertSame('grace@example.test', $savedGrace->email());
        self::assertSame('Grace', $savedGrace->firstName());
        self::assertNotNull($savedGraceAssignment);
        self::assertSame('grace-ordf@example.test', $savedGraceAssignment->publicContact());
        self::assertCount(1, $events);
        self::assertSame('anonymize_person', $events[0]->action());
        self::assertSame(7, $events[0]->actorUserId());
        self::assertStringNotContainsString('ada@example.test', $events[0]->action());
        self::assertSame([], $erase->erase('', null, 7));
    }

    public function test_erase_without_capability_changes_nothing(): void
    {
        $people = new MemoryPersonRepository();
        $ada = $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, 4));
        $erase = $this->eraser(
            $people,
            new MemoryMembershipRepository(),
            new MemoryBoardAssignmentRepository(),
            new MemoryParticipantRepository(),
            new MemoryMeetingRepository(),
            new MemoryMinutesRepository(),
            new MemorySignedCopyRepository(),
            new MemoryAuditLog(),
            false
        );

        try {
            $erase->erase('ada@example.test', null, 7);
            self::fail('Erasure was allowed.');
        } catch (NotAllowed $error) {
            self::assertSame(Capabilities::ERASE_MEMBER_DATA, $error->getMessage());
        }

        $saved = $people->find((int) $ada->id());

        self::assertNotNull($saved);
        self::assertSame('Ada', $saved->firstName());
        self::assertSame('ada@example.test', $saved->email());
        self::assertSame(4, $saved->wordpressUserId());
    }

    private function eraser(
        MemoryPersonRepository $people,
        MemoryMembershipRepository $memberships,
        MemoryBoardAssignmentRepository $assignments,
        MemoryParticipantRepository $participants,
        MemoryMeetingRepository $meetings,
        MemoryMinutesRepository $minutes,
        MemorySignedCopyRepository $copies,
        MemoryAuditLog $audit,
        bool $allowed,
    ): PrivacyErase {
        return new PrivacyErase(
            $people,
            $memberships,
            $assignments,
            $participants,
            $meetings,
            $minutes,
            $copies,
            $audit,
            new class ($allowed) implements Authorizer {
                public function __construct(private readonly bool $allowed)
                {
                }

                public function allows(string $capability): bool
                {
                    return $this->allowed && $capability === Capabilities::ERASE_MEMBER_DATA;
                }
            },
            new class implements Transaction {
                public function run(callable $callback): mixed
                {
                    return $callback();
                }
            }
        );
    }
}
