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
        $memberships->add(new MembershipPeriod(null, (int) $active->id(), 'M-ACTIVE', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null));
        $memberships->add(new MembershipPeriod(null, (int) $active->id(), 'M-OLD', 'ordinarie', MembershipStatus::Ended, AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('2022-01-01')));
        $memberships->add(new MembershipPeriod(null, (int) $ended->id(), 'M-ENDED', 'ordinarie', MembershipStatus::Ended, AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('2024-01-01')));
        $memberships->add(new MembershipPeriod(null, (int) $pending->id(), 'M-PENDING', 'ordinarie', MembershipStatus::Pending, AssociationDate::fromIso('2024-01-01'), null));
        $memberships->add(new MembershipPeriod(null, (int) $dormant->id(), 'M-DORMANT', 'ordinarie', MembershipStatus::Dormant, AssociationDate::fromIso('2024-01-01'), null));
        $memberships->add(new MembershipPeriod(null, (int) $closedActive->id(), 'M-CLOSED', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), AssociationDate::fromIso('2024-06-01')));
        $memberships->add(new MembershipPeriod(null, (int) $unlinked->id(), 'M-OPEN', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null));
        $access = new MemberDocumentAccess($people, $memberships);

        self::assertTrue($access->allows(9));
        self::assertFalse($access->allows(8));
        self::assertFalse($access->allows(7));
        self::assertFalse($access->allows(6));
        self::assertFalse($access->allows(5));
        self::assertFalse($access->allows(4));
        self::assertFalse($access->allows(0));
    }
}