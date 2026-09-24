<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Application\Privacy\Retention;
use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use Foreningssystem\Domain\Privacy\RetentionPeriod;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RetentionTest extends TestCase
{
    public function test_retention_anonymizes_after_the_membership_ended_and_removes_old_audit_events(): void
    {
        $today = AssociationDate::fromIso('2026-09-24');
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $assignments = new MemoryBoardAssignmentRepository();
        $audit = new MemoryAuditLog();
        $roleId = (int) (new MemoryBoardRoleRepository())->add(new BoardRole(null, 'chair', 'Ordförande', false, 10))->id();
        $ada = $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, 4));
        $grace = $people->add(new Person(null, 'Grace', 'Hemlig', 'grace@example.test', PersonStatus::Known, null));
        $kim = $people->add(new Person(null, 'Kim', 'Aktiv', 'kim@example.test', PersonStatus::Known, null));
        $nils = $people->add(new Person(null, 'Nils', 'Oppet', 'nils@example.test', PersonStatus::Known, null));
        $ann = $people->add(new Person(null, 'Ann', 'Dag', 'ann@example.test', PersonStatus::Deceased, null));
        $bea = $people->add(new Person(null, 'Bea', 'Sen', 'bea@example.test', PersonStatus::Known, null));
        $adaId = (int) $ada->id();
        $memberships->grant($adaId, 'M-ADA', 'ordinarie', MembershipStatus::Ended, AssociationDate::fromIso('2018-01-01'), AssociationDate::fromIso('2020-01-01'));
        $memberships->grant((int) $grace->id(), 'M-GRACE', 'ordinarie', MembershipStatus::Ended, AssociationDate::fromIso('2024-01-01'), AssociationDate::fromIso('2025-09-24'));
        $memberships->grant((int) $kim->id(), 'M-KIM', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $memberships->grant((int) $nils->id(), 'M-NILS-OLD', 'ordinarie', MembershipStatus::Ended, AssociationDate::fromIso('2008-01-01'), AssociationDate::fromIso('2010-01-01'));
        $memberships->grant((int) $nils->id(), 'M-NILS', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $memberships->grant((int) $ann->id(), 'M-ANN', 'ordinarie', MembershipStatus::Ended, AssociationDate::fromIso('2019-01-01'), AssociationDate::fromIso('2021-09-24'));
        $memberships->grant((int) $bea->id(), 'M-BEA', 'ordinarie', MembershipStatus::Ended, AssociationDate::fromIso('2019-01-01'), AssociationDate::fromIso('2021-09-25'));
        $adaAssignment = $assignments->add(new BoardAssignment(null, $adaId, $roleId, AssociationDate::fromIso('2018-01-01'), AssociationDate::fromIso('2020-01-01'), 'ada@example.test', '2018'));
        $assignments->add(new BoardAssignment(null, (int) $grace->id(), $roleId, AssociationDate::fromIso('2024-01-01'), AssociationDate::fromIso('2025-09-24'), 'grace-ordf@example.test', ''));
        $audit->recordAt('person', $adaId, 'export_personal_data', 7, '2020-01-01 12:00:00');
        $audit->recordAt('person', (int) $grace->id(), 'export_personal_data', 7, '2024-06-01 12:00:00');
        $retention = $this->retention($people, $memberships, $assignments, $audit);

        $result = $retention->apply($today, RetentionPeriod::default(), 0);
        $savedAda = $people->find($adaId);
        $savedAssignment = $assignments->find((int) $adaAssignment->id());
        $adaPeriod = $this->period($memberships, 'M-ADA');

        self::assertSame(5, RetentionPeriod::default()->years());
        self::assertSame('2021-03-01', AssociationDate::fromIso('2020-02-29')->plusYears(1)->iso());
        self::assertSame('2021-09-24', $today->minusYears(5)->iso());
        self::assertSame(2, $result->anonymized());
        self::assertSame(1, $result->removedAudits());
        self::assertNotNull($savedAda);
        self::assertSame(Person::ANONYMOUS_FIRST_NAME, $savedAda->firstName());
        self::assertSame('', $savedAda->email());
        self::assertNull($savedAda->wordpressUserId());
        self::assertSame(PersonStatus::Known, $savedAda->status());
        self::assertNotNull($adaPeriod);
        self::assertSame(MembershipStatus::Ended, $adaPeriod->status());
        self::assertSame('2020-01-01', $adaPeriod->endedOn()?->iso());
        self::assertNotNull($savedAssignment);
        self::assertSame('', $savedAssignment->publicContact());
        self::assertSame('2018-01-01', $savedAssignment->startedOn()->iso());
        self::assertSame('2020-01-01', $savedAssignment->endedOn()?->iso());
        self::assertSame('2018', $savedAssignment->termLabel());
        self::assertSame(PersonStatus::Deceased, $people->find((int) $ann->id())?->status());
        self::assertSame('', $people->find((int) $ann->id())?->email());
        self::assertSame('grace@example.test', $people->find((int) $grace->id())?->email());
        self::assertSame('grace-ordf@example.test', $this->contact($assignments, (int) $grace->id()));
        self::assertSame('kim@example.test', $people->find((int) $kim->id())?->email());
        self::assertSame(MembershipStatus::Active, $this->period($memberships, 'M-KIM')?->status());
        self::assertCount(7, $memberships->all());
        self::assertCount(2, $assignments->all());
        self::assertSame('nils@example.test', $people->find((int) $nils->id())?->email());
        self::assertSame('bea@example.test', $people->find((int) $bea->id())?->email());
        $events = $audit->forObject('person', $adaId);
        self::assertCount(1, $events);
        self::assertSame('anonymize_person', $events[0]->action());
        self::assertSame(0, $events[0]->actorUserId());
        self::assertStringNotContainsString('ada@example.test', $events[0]->action());
        self::assertCount(1, $audit->forObject('person', (int) $grace->id()));
        self::assertSame('export_personal_data', $audit->forObject('person', (int) $grace->id())[0]->action());

        $again = $retention->apply($today, new RetentionPeriod(5), 0);

        self::assertSame(0, $again->anonymized());
        self::assertCount(1, $audit->forObject('person', $adaId));
    }

    public function test_retention_period_rejects_a_zero_year_setting(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RetentionPeriod(0);
    }

    private function retention(
        MemoryPersonRepository $people,
        MemoryMembershipRepository $memberships,
        MemoryBoardAssignmentRepository $assignments,
        MemoryAuditLog $audit,
    ): Retention {
        return new Retention(
            $people,
            $memberships,
            $assignments,
            $audit,
            new class implements Transaction {
                public function run(callable $callback): mixed
                {
                    return $callback();
                }
            },
            new MemoryPersonalIdentityRepository()
        );
    }

    private function period(MemoryMembershipRepository $memberships, string $number): ?MembershipPeriod
    {
        $account = $memberships->findMembershipByNumber($number);

        if ($account === null || $account->id() === null) {
            return null;
        }

        $periods = $memberships->periodsForMembership($account->id());

        return $periods[0] ?? null;
    }

    private function contact(MemoryBoardAssignmentRepository $assignments, int $personId): string
    {
        foreach ($assignments->all() as $assignment) {
            if ($assignment->personId() === $personId) {
                return $assignment->publicContact();
            }
        }

        return '';
    }
}
