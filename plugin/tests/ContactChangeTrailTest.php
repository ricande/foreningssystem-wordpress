<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\ContactChanged;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\PeopleService;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Application\Privacy\PrivacyErase;
use Foreningssystem\Application\Privacy\PrivacyExport;
use Foreningssystem\Application\Privacy\Retention;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\AuditEvent;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Privacy\RetentionPeriod;
use PHPUnit\Framework\TestCase;

/**
 * A contact edit leaves one value-free audit event per changed part. See ADR-0032.
 */
final class ContactChangeTrailTest extends TestCase
{
    private const TODAY = '2025-03-01';

    public function test_each_changed_part_is_recorded_once_with_the_actor_and_no_value(): void
    {
        [$service, $people, $audit] = $this->fixture();
        $personId = $service->rememberPerson('Ada', 'Lovelace', 'ada@example.test', AssociationDate::fromIso('1990-01-01'), $this->today());

        $service->updateContact($personId, 'Ada', 'King', 'ada@example.test', AssociationDate::fromIso('1990-01-01'), $this->today(), 7);

        self::assertSame(['contact_name_changed'], $this->actions($audit, $personId));
        self::assertSame(7, $audit->forObject('person', $personId)[0]->actorUserId());

        $service->updateContact($personId, 'Ada', 'King', 'augusta@example.test', AssociationDate::fromIso('1991-02-03'), $this->today(), 8);

        self::assertSame(
            ['contact_name_changed', 'contact_email_changed', 'contact_birth_date_changed'],
            $this->actions($audit, $personId)
        );
        self::assertSame(8, $audit->forObject('person', $personId)[2]->actorUserId());

        foreach ($audit->forObject('person', $personId) as $event) {
            foreach (['Ada', 'Lovelace', 'King', 'ada@example.test', 'augusta@example.test', '1990', '1991'] as $value) {
                self::assertStringNotContainsString($value, $event->action());
            }
        }
    }

    public function test_a_change_of_letter_case_or_a_removed_birth_date_counts(): void
    {
        [$service, , $audit] = $this->fixture();
        $personId = $service->rememberPerson('Ada', 'Lovelace', 'ada@example.test', AssociationDate::fromIso('1990-01-01'), $this->today());

        $service->updateContact($personId, 'Ada', 'Lovelace', 'Ada@Example.test', null, $this->today(), 7);

        self::assertSame(['contact_email_changed', 'contact_birth_date_changed'], $this->actions($audit, $personId));
    }

    public function test_a_save_without_changes_or_with_only_surrounding_spaces_writes_no_event(): void
    {
        [$service, $people, $audit] = $this->fixture();
        $personId = $service->rememberPerson('Ada', 'Lovelace', 'ada@example.test', null, $this->today());

        $service->updateContact($personId, ' Ada ', 'Lovelace ', ' ada@example.test', null, $this->today(), 7);

        self::assertSame([], $this->actions($audit, $personId));
        self::assertSame('Ada', $people->find($personId)?->firstName());
    }

    public function test_a_refused_save_writes_no_event(): void
    {
        [$service, $people, $audit] = $this->fixture();
        $personId = $service->rememberPerson('Ada', 'Lovelace', 'ada@example.test', null, $this->today());
        $opened = (string) $people->find($personId)?->contactVersion();
        $service->updateContact($personId, 'Ada', 'King', 'ada@example.test', null, $this->today(), 7, $opened);

        try {
            $service->updateContact($personId, 'Augusta', 'Lovelace', 'other@example.test', null, $this->today(), 8, $opened);
            self::fail('A stale form should be refused.');
        } catch (ContactChanged) {
        }

        try {
            $service->updateContact($personId, 'Ada', 'King', 'ada@example.test', AssociationDate::fromIso('2030-01-01'), $this->today(), 8);
            self::fail('A birth date in the future should be refused.');
        } catch (\InvalidArgumentException) {
        }

        [$denied] = $this->fixture(false, $people, $audit);

        try {
            $denied->updateContact($personId, 'Mallory', 'King', 'ada@example.test', null, $this->today(), 9);
            self::fail('An officer without edit_members should be refused.');
        } catch (NotAllowed) {
        }

        self::assertSame(['contact_name_changed'], $this->actions($audit, $personId));
        self::assertSame(7, $audit->forObject('person', $personId)[0]->actorUserId());
    }

    public function test_erase_keeps_the_value_free_events_and_adds_its_own(): void
    {
        [$service, $people, $audit] = $this->fixture();
        $personId = $service->rememberPerson('Ada', 'Lovelace', 'old@example.test', null, $this->today());
        $service->updateContact($personId, 'Ada', 'Lovelace', 'ada@example.test', null, $this->today(), 7);

        $outcomes = $this->eraser($people, $audit)->erase('ada@example.test', null, 3);

        self::assertCount(1, $outcomes);
        self::assertSame(Person::ANONYMOUS_FIRST_NAME, $people->find($personId)?->firstName());
        self::assertSame('', $people->find($personId)?->email());
        self::assertSame(['contact_email_changed', 'anonymize_person'], $this->actions($audit, $personId));
        self::assertSame([], $this->eraser($people, $audit)->erase('old@example.test', null, 3));
    }

    public function test_retention_removes_contact_events_with_the_other_old_audit_events(): void
    {
        [, $people, $audit] = $this->fixture();
        $person = $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', \Foreningssystem\Domain\Person\PersonStatus::Known, null));
        $personId = (int) $person->id();
        $audit->recordAt('person', $personId, 'contact_email_changed', 7, '2020-06-01 10:00:00');
        $audit->recordAt('person', $personId, 'contact_name_changed', 7, '2024-06-02 10:00:00');
        $retention = new Retention(
            $people,
            new MemoryMembershipRepository(),
            new MemoryBoardAssignmentRepository(),
            $audit,
            $this->passthrough(),
            new MemoryPersonalIdentityRepository()
        );

        $result = $retention->apply(AssociationDate::fromIso('2029-06-01'), RetentionPeriod::default(), 0);

        self::assertSame(1, $result->removedAudits());
        self::assertSame(['contact_name_changed'], $this->actions($audit, $personId));
        self::assertSame('2024-06-02 10:00:00', $audit->forObject('person', $personId)[0]->createdAt());
    }

    public function test_the_privacy_export_lists_day_and_part_but_not_the_officer(): void
    {
        [$service, $people, $audit] = $this->fixture();
        $memberships = new MemoryMembershipRepository();
        $personId = $service->rememberPerson('Ada', 'Lovelace', 'old@example.test', null, $this->today());
        $memberships->grant($personId, 'M-ADA', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $service->updateContact($personId, 'Ada', 'King', 'ada@example.test', AssociationDate::fromIso('1990-01-01'), $this->today(), 7);
        $audit->record('person', $personId, 'export_personal_data', 7);
        $export = new PrivacyExport(
            $people,
            $memberships,
            new MemoryBoardAssignmentRepository(),
            new MemoryBoardRoleRepository(),
            new MemoryParticipantRepository(),
            new MemoryMeetingRepository(),
            $audit,
            new MemoryPersonalIdentityRepository(),
            new MemoryGuardianRepository()
        );

        $report = $export->collect('ada@example.test', null, 3);

        self::assertCount(1, $report->people());
        self::assertSame(
            [
                ['on' => '2024-05-02', 'part' => 'name'],
                ['on' => '2024-05-02', 'part' => 'email'],
                ['on' => '2024-05-02', 'part' => 'birth_date'],
            ],
            $report->people()[0]->contactChanges()
        );
    }

    /**
     * @return array{0: PeopleService, 1: MemoryPersonRepository, 2: MemoryAuditLog}
     */
    private function fixture(bool $allowed = true, ?MemoryPersonRepository $people = null, ?MemoryAuditLog $audit = null): array
    {
        $people ??= new MemoryPersonRepository();
        $audit ??= new MemoryAuditLog();
        $service = new PeopleService(
            $people,
            new MemoryMembershipRepository(),
            new MembershipLedger(),
            new class ($allowed) implements Authorizer {
                public function __construct(private readonly bool $allowed)
                {
                }

                public function allows(string $capability): bool
                {
                    return $this->allowed && in_array($capability, [Capabilities::EDIT_MEMBERS, Capabilities::VIEW_MEMBERS], true);
                }
            },
            $this->passthrough(),
            new RecordingOpenAssignments(),
            new UnheldRegisterLock(),
            $audit
        );

        return [$service, $people, $audit];
    }

    private function eraser(MemoryPersonRepository $people, MemoryAuditLog $audit): PrivacyErase
    {
        return new PrivacyErase(
            $people,
            new MemoryMembershipRepository(),
            new MemoryBoardAssignmentRepository(),
            new MemoryParticipantRepository(),
            new MemoryMeetingRepository(),
            new MemoryMinutesRepository(),
            new MemorySignedCopyRepository(),
            $audit,
            new class implements Authorizer {
                public function allows(string $capability): bool
                {
                    return $capability === Capabilities::ERASE_MEMBER_DATA;
                }
            },
            $this->passthrough(),
            new MemoryPersonalIdentityRepository()
        );
    }

    private function passthrough(): Transaction
    {
        return new class implements Transaction {
            public function run(callable $callback): mixed
            {
                return $callback();
            }
        };
    }

    /**
     * @return list<string>
     */
    private function actions(MemoryAuditLog $audit, int $personId): array
    {
        return array_map(static fn (AuditEvent $event): string => $event->action(), $audit->forObject('person', $personId));
    }

    private function today(): AssociationDate
    {
        return AssociationDate::fromIso(self::TODAY);
    }
}
