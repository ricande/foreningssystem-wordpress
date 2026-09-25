<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Document\MemberDocumentAccess;
use Foreningssystem\Application\Document\WordPressIdentity;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\Membership;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipParticipant;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Membership\ParticipantRole;
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
        $access = new MemberDocumentAccess($people, $memberships, new KnownWordPressUsers([9, 8, 7, 6, 5, 3]));
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
        $access = new MemberDocumentAccess($people, $memberships, new KnownWordPressUsers([9]));

        self::assertFalse($access->allows(9, AssociationDate::fromIso('2024-06-15')));
    }

    public function test_member_access_requires_a_live_wordpress_user_the_person_link_and_active_coverage(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $today = AssociationDate::fromIso('2024-06-15');
        $live = $people->add(new Person(null, 'Ada', 'Medlem', 'ada-live@example.test', PersonStatus::Known, 39));
        $ended = $people->add(new Person(null, 'Bo', 'Slut', 'bo-ended@example.test', PersonStatus::Known, 38));
        $unlinked = $people->add(new Person(null, 'Otto', 'Los', 'anna@example.test', PersonStatus::Known, null));
        $family = $people->add(new Person(null, 'Erik', 'Familj', 'erik-family@example.test', PersonStatus::Known, 40));
        $contact = $people->add(new Person(null, 'Kim', 'Kontakt', 'kim-contact@example.test', PersonStatus::Known, 41));
        $broken = $people->add(new Person(null, 'Anna', 'Trasig', 'anna-broken@example.test', PersonStatus::Known, 37));
        $memberships->grant((int) $live->id(), 'M-LIVE', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $memberships->grant((int) $ended->id(), 'M-ENDED', 'ordinarie', MembershipStatus::Ended, AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('2024-01-01'));
        $memberships->grant((int) $unlinked->id(), 'M-OPEN', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $memberships->grant((int) $family->id(), 'FAM-1', 'family', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null, MembershipKind::Family);
        $company = $memberships->addMembership(new Membership(null, 'C-1', MembershipKind::Company, 1));
        $memberships->addParticipant(new MembershipParticipant(
            null,
            (int) $company->id(),
            (int) $contact->id(),
            ParticipantRole::Contact,
            true,
            AssociationDate::fromIso('2024-01-01'),
            null
        ));
        $memberships->add(new MembershipPeriod(
            null,
            (int) $company->id(),
            MembershipStatus::Active,
            AssociationDate::fromIso('2024-01-01'),
            null,
            'company'
        ));
        $memberships->grant((int) $broken->id(), 'M-BROKEN', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $access = new MemberDocumentAccess($people, $memberships, new KnownWordPressUsers([39, 38, 40, 41, 81, 82]));

        self::assertFalse($access->allows(0, $today));
        self::assertFalse($access->allows(-1, $today));
        self::assertTrue($access->allows(39, $today));
        self::assertFalse($access->allows(37, $today));
        self::assertFalse($access->allows(38, $today));
        self::assertFalse($access->allows(81, $today));
        self::assertFalse($access->allows(82, $today));
        self::assertTrue($access->allows(40, $today));
        self::assertFalse($access->allows(41, $today));
    }
}

final class KnownWordPressUsers implements WordPressIdentity
{
    /** @param list<int> $userIds */
    public function __construct(private readonly array $userIds)
    {
    }

    public function exists(int $userId): bool
    {
        return in_array($userId, $this->userIds, true);
    }
}