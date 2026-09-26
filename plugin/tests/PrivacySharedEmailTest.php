<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Application\Privacy\PrivacyErase;
use Foreningssystem\Application\Privacy\PrivacyExport;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Identity\PersonalIdentityNumber;
use Foreningssystem\Domain\Identity\PersonalIdentityRecord;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use PHPUnit\Framework\TestCase;

/**
 * A family shares one email address. A privacy request for that address names nobody in
 * particular, so it must not hand out or anonymize the whole family.
 */
final class PrivacySharedEmailTest extends TestCase
{
    public function test_a_unique_email_exports_that_person(): void
    {
        $people = new MemoryPersonRepository();
        $ada = $people->add(new Person(null, 'Ada', 'Lovelace', 'ada-unique@example.test', PersonStatus::Known, null));

        $report = $this->export($people)->collect(' Ada-Unique@Example.Test ', null, 7)->people();

        self::assertCount(1, $report);
        self::assertSame((int) $ada->id(), $report[0]->id());
    }

    public function test_a_unique_account_link_exports_that_person_even_with_another_email(): void
    {
        $people = new MemoryPersonRepository();
        $people->add(new Person(null, 'Ada', 'Lovelace', 'ada-link@example.test', PersonStatus::Known, null));
        $kim = $people->add(new Person(null, 'Kim', 'Konto', 'kim-link@example.test', PersonStatus::Known, 9));

        $report = $this->export($people)->collect('nobody@example.test', 9, 7)->people();

        self::assertCount(1, $report);
        self::assertSame((int) $kim->id(), $report[0]->id());
        self::assertSame('kim-link@example.test', $report[0]->email());
    }

    public function test_an_email_two_people_share_exports_nobody(): void
    {
        $people = new MemoryPersonRepository();
        $anna = $people->add(new Person(null, 'Anna', 'Berg', 'familjen@example.test', PersonStatus::Known, null));
        $lisa = $people->add(new Person(null, 'Lisa', 'Berg', 'familjen@example.test', PersonStatus::Known, null));
        $audit = new MemoryAuditLog();

        $report = $this->export($people, $audit)->collect('familjen@example.test', null, 7);

        self::assertSame([], $report->people());
        self::assertSame([], $audit->forObject('person', (int) $anna->id()));
        self::assertSame([], $audit->forObject('person', (int) $lisa->id()));
    }

    public function test_a_shared_email_follows_the_account_link_and_leaves_the_family_out(): void
    {
        $people = new MemoryPersonRepository();
        $identities = new MemoryPersonalIdentityRepository();
        $anna = $people->add(new Person(null, 'Anna', 'Berg', 'familjen@example.test', PersonStatus::Known, 12));
        $lisa = $people->add(new Person(null, 'Lisa', 'Berg', 'familjen@example.test', PersonStatus::Known, null));
        $identities->save(new PersonalIdentityRecord(
            null,
            (int) $lisa->id(),
            PersonalIdentityNumber::parse('20120417-0011', AssociationDate::fromIso('2026-09-24')),
            'Association administration',
            'Association policy',
            AssociationDate::fromIso('2026-09-24'),
            4
        ));

        $report = $this->export($people, new MemoryAuditLog(), $identities)->collect('familjen@example.test', 12, 7)->people();

        self::assertCount(1, $report);
        self::assertSame((int) $anna->id(), $report[0]->id());
        self::assertSame('Anna', $report[0]->firstName());
        self::assertNull($report[0]->personalIdentityNumber());
    }

    public function test_an_email_two_people_share_anonymizes_nobody(): void
    {
        $people = new MemoryPersonRepository();
        $anna = $people->add(new Person(null, 'Anna', 'Berg', 'familjen@example.test', PersonStatus::Known, null));
        $lisa = $people->add(new Person(null, 'Lisa', 'Berg', 'familjen@example.test', PersonStatus::Known, null));
        $audit = new MemoryAuditLog();

        $outcomes = $this->eraser($people, $audit)->erase('familjen@example.test', null, 7);

        self::assertSame([], $outcomes);
        self::assertSame('Anna', $people->find((int) $anna->id())?->firstName());
        self::assertSame('Lisa', $people->find((int) $lisa->id())?->firstName());
        self::assertSame('familjen@example.test', $people->find((int) $lisa->id())?->email());
        self::assertSame([], $audit->forObject('person', (int) $anna->id()));
        self::assertSame([], $audit->forObject('person', (int) $lisa->id()));
    }

    public function test_a_shared_email_with_one_account_link_anonymizes_only_the_linked_person(): void
    {
        $people = new MemoryPersonRepository();
        $anna = $people->add(new Person(null, 'Anna', 'Berg', 'familjen@example.test', PersonStatus::Known, 12));
        $lisa = $people->add(new Person(null, 'Lisa', 'Berg', 'familjen@example.test', PersonStatus::Known, null));

        $outcomes = $this->eraser($people)->erase('familjen@example.test', 12, 7);
        $savedAnna = $people->find((int) $anna->id());
        $savedLisa = $people->find((int) $lisa->id());

        self::assertCount(1, $outcomes);
        self::assertSame((int) $anna->id(), $outcomes[0]->personId());
        self::assertSame(Person::ANONYMOUS_FIRST_NAME, $savedAnna?->firstName());
        self::assertSame('', $savedAnna?->email());
        self::assertNull($savedAnna?->wordpressUserId());
        self::assertSame('Lisa', $savedLisa?->firstName());
        self::assertSame('familjen@example.test', $savedLisa?->email());
    }

    public function test_a_duplicated_account_link_is_not_an_identity(): void
    {
        $people = new MemoryPersonRepository();
        $anna = $people->add(new Person(null, 'Anna', 'Berg', 'anna-dup@example.test', PersonStatus::Known, 12));
        $lisa = $people->add(new Person(null, 'Lisa', 'Berg', 'lisa-dup@example.test', PersonStatus::Known, 12));

        $exported = $this->export($people)->collect('anna-dup@example.test', 12, 7)->people();
        $outcomes = $this->eraser($people)->erase('anna-dup@example.test', 12, 7);

        self::assertSame([], $exported);
        self::assertSame([], $outcomes);
        self::assertSame('Anna', $people->find((int) $anna->id())?->firstName());
        self::assertSame('Lisa', $people->find((int) $lisa->id())?->firstName());
    }

    public function test_an_anonymized_record_is_not_matched_by_an_empty_email(): void
    {
        $people = new MemoryPersonRepository();
        $anna = $people->add(new Person(null, 'Anna', 'Berg', 'anna-gone@example.test', PersonStatus::Known, null));
        $people->save($anna->anonymized());

        self::assertSame([], $this->export($people)->collect('', null, 7)->people());
        self::assertSame([], $this->eraser($people)->erase('', null, 7));
    }

    private function export(
        MemoryPersonRepository $people,
        ?MemoryAuditLog $audit = null,
        ?MemoryPersonalIdentityRepository $identities = null,
    ): PrivacyExport {
        return new PrivacyExport(
            $people,
            new MemoryMembershipRepository(),
            new MemoryBoardAssignmentRepository(),
            new MemoryBoardRoleRepository(),
            new MemoryParticipantRepository(),
            new MemoryMeetingRepository(),
            $audit ?? new MemoryAuditLog(),
            $identities ?? new MemoryPersonalIdentityRepository(),
            new MemoryGuardianRepository()
        );
    }

    private function eraser(MemoryPersonRepository $people, ?MemoryAuditLog $audit = null): PrivacyErase
    {
        return new PrivacyErase(
            $people,
            new MemoryMembershipRepository(),
            new MemoryBoardAssignmentRepository(),
            new MemoryParticipantRepository(),
            new MemoryMeetingRepository(),
            new MemoryMinutesRepository(),
            new MemorySignedCopyRepository(),
            $audit ?? new MemoryAuditLog(),
            new class implements Authorizer {
                public function allows(string $capability): bool
                {
                    return $capability === Capabilities::ERASE_MEMBER_DATA;
                }
            },
            new class implements Transaction {
                public function run(callable $callback): mixed
                {
                    return $callback();
                }
            },
            new MemoryPersonalIdentityRepository()
        );
    }
}
