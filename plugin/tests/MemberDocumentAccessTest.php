<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Document\MemberDocumentAccess;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use PHPUnit\Framework\TestCase;

final class MemberDocumentAccessTest extends TestCase
{
    public function test_only_a_linked_active_membership_can_read_member_documents(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $active = $people->add(new Person(null, 'Ada', 'Medlem', 'ada-member@example.test', PersonStatus::Known, 9));
        $ended = $people->add(new Person(null, 'Bo', 'Slut', 'bo-member@example.test', PersonStatus::Known, 8));
        $pending = $people->add(new Person(null, 'Kim', 'Vantar', 'kim-member@example.test', PersonStatus::Known, 7));
        $dormant = $people->add(new Person(null, 'Noa', 'Vila', 'noa-member@example.test', PersonStatus::Known, 6));
        $closedActive = $people->add(new Person(null, 'Eva', 'Datum', 'eva-member@example.test', PersonStatus::Known, 5));
        $unlinked = $people->add(new Person(null, 'Otto', 'Los', 'otto-member@example.test', PersonStatus::Known, null));
        $memberships->grant((int) $active->id(), 'M-ACTIVE', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $memberships->grant((int) $active->id(), 'M-OLD', 'ordinarie', MembershipStatus::Ended, AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('2022-01-01'));
        $memberships->grant((int) $ended->id(), 'M-ENDED', 'ordinarie', MembershipStatus::Ended, AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('2024-01-01'));
        $memberships->grant((int) $pending->id(), 'M-PENDING', 'ordinarie', MembershipStatus::Pending, AssociationDate::fromIso('2024-01-01'), null);
        $memberships->grant((int) $dormant->id(), 'M-DORMANT', 'ordinarie', MembershipStatus::Dormant, AssociationDate::fromIso('2024-01-01'), null);
        $memberships->grant((int) $closedActive->id(), 'M-CLOSED', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), AssociationDate::fromIso('2024-06-01'));
        $memberships->grant((int) $unlinked->id(), 'M-OPEN', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $future = $people->add(new Person(null, 'Futura', 'Senare', 'futura-member@example.test', PersonStatus::Known, 3));
        $memberships->grant((int) $future->id(), 'M-FUTURE', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-06-16'), null);
        $access = new MemberDocumentAccess($people, $memberships);
        $today = AssociationDate::fromIso('2024-06-15');

        self::assertTrue($access->allows(9, $today));
        self::assertFalse($access->allows(8, $today));
        self::assertFalse($access->allows(7, $today));
        self::assertFalse($access->allows(6, $today));
        self::assertFalse($access->allows(5, $today));
        self::assertFalse($access->allows(4, $today));
        self::assertFalse($access->allows(3, $today));
        self::assertFalse($access->allows(0, $today));
    }

    public function test_the_same_email_without_a_person_link_does_not_grant_member_documents(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $person = $people->add(new Person(null, 'Ada', 'Medlem', 'ada-member@example.test', PersonStatus::Known, null));
        $memberships->grant((int) $person->id(), 'M-EMAIL', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $access = new MemberDocumentAccess($people, $memberships);

        self::assertFalse($access->allows(9, AssociationDate::fromIso('2024-06-15')));
    }
}