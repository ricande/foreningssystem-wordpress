<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Board\EndOpenBoardAssignments;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\MemberExchange;
use Foreningssystem\Application\People\PeopleService;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardAssignmentLedger;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MemberCoverage;
use Foreningssystem\Domain\Membership\Membership;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipParticipant;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Domain\Person\PersonStatus;
use PHPUnit\Framework\TestCase;

final class CoverageInvariantTest extends TestCase
{
    public function test_ending_the_only_participation_truncates_the_open_assignment(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-cover@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2026-01-01'));
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $assignment = $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2026-03-01'), null, '', ''));

        $service->endParticipation($membershipId, $personId, AssociationDate::fromIso('2026-09-24'));

        self::assertSame('2026-09-24', $assignments->find((int) $assignment->id())?->endedOn()?->iso());
    }

    public function test_another_membership_that_continues_the_next_day_keeps_the_assignment(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-next@example.test', 'M-1', 'family', AssociationDate::fromIso('2026-01-01'));
        $familyId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $period = $memberships->periodsForMembership($familyId)[0];
        $memberships->save((new MembershipLedger())->end($period, AssociationDate::fromIso('2026-09-24')));
        $service->openForExistingPerson($personId, 'M-2', 'ordinary', AssociationDate::fromIso('2026-09-25'));
        $assignment = $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2026-03-01'), null, '', ''));

        $service->endParticipation($familyId, $personId, AssociationDate::fromIso('2026-09-24'));

        self::assertNull($assignments->find((int) $assignment->id())?->endedOn());
    }

    public function test_a_gap_before_the_next_membership_truncates_the_assignment(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-gap@example.test', 'M-1', 'family', AssociationDate::fromIso('2026-01-01'));
        $familyId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $period = $memberships->periodsForMembership($familyId)[0];
        $memberships->save((new MembershipLedger())->end($period, AssociationDate::fromIso('2026-09-24')));
        $service->openForExistingPerson($personId, 'M-2', 'ordinary', AssociationDate::fromIso('2026-09-26'));
        $assignment = $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2026-03-01'), null, '', ''));

        $service->endParticipation($familyId, $personId, AssociationDate::fromIso('2026-09-24'));

        self::assertSame('2026-09-24', $assignments->find((int) $assignment->id())?->endedOn()?->iso());
    }

    public function test_a_future_participation_end_truncates_the_assignment_on_that_date(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-future@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2026-01-01'));
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $assignment = $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2026-03-01'), null, '', ''));

        $service->endParticipation($membershipId, $personId, AssociationDate::fromIso('2026-12-31'));

        self::assertSame('2026-12-31', $assignments->find((int) $assignment->id())?->endedOn()?->iso());
    }

    public function test_an_assignment_that_already_ends_inside_coverage_is_left_unchanged(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-closed@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2026-01-01'));
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $assignment = $assignments->add(new BoardAssignment(
            null,
            $personId,
            1,
            AssociationDate::fromIso('2026-01-01'),
            AssociationDate::fromIso('2026-06-30'),
            '',
            ''
        ));

        $service->endParticipation($membershipId, $personId, AssociationDate::fromIso('2026-09-24'));

        self::assertSame('2026-06-30', $assignments->find((int) $assignment->id())?->endedOn()?->iso());
    }

    public function test_an_assignment_that_runs_past_coverage_is_shortened_to_the_coverage_end(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-long@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2026-01-01'));
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $assignment = $assignments->add(new BoardAssignment(
            null,
            $personId,
            1,
            AssociationDate::fromIso('2026-01-01'),
            AssociationDate::fromIso('2026-12-31'),
            '',
            ''
        ));

        $service->endParticipation($membershipId, $personId, AssociationDate::fromIso('2026-09-24'));

        self::assertSame('2026-09-24', $assignments->find((int) $assignment->id())?->endedOn()?->iso());
    }

    public function test_ending_a_contact_participation_does_not_change_the_assignment(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-contact@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2026-01-01'));
        $contact = $memberships->addMembership(new Membership(null, 'K-1', MembershipKind::Company, 1));
        $memberships->add(new MembershipPeriod(null, (int) $contact->id(), MembershipStatus::Active, AssociationDate::fromIso('2026-01-01'), null, 'company'));
        $service->addParticipant((int) $contact->id(), $personId, ParticipantRole::Contact, false, AssociationDate::fromIso('2026-01-01'));
        $assignment = $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2026-03-01'), null, '', ''));

        $service->endParticipation((int) $contact->id(), $personId, AssociationDate::fromIso('2026-09-24'));

        self::assertNull($assignments->find((int) $assignment->id())?->endedOn());
        $ended = $memberships->participantsForMembership((int) $contact->id())[0];
        self::assertSame('2026-09-24', $ended->endedOn()?->iso());
        self::assertFalse($ended->role()->countsAsMember());
    }

    public function test_ending_participation_before_the_assignment_starts_is_rejected(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-before@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2026-01-01'));
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $assignment = $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2026-03-01'), null, '', ''));

        try {
            $service->endParticipation($membershipId, $personId, AssociationDate::fromIso('2026-02-01'));
            self::fail('An uncovered assignment start should reject the participation end.');
        } catch (MembershipRuleException $error) {
            self::assertSame('The membership does not cover this assignment.', $error->getMessage());
        }

        self::assertNull($assignments->find((int) $assignment->id())?->endedOn());
        self::assertNull($memberships->participantsForMembership($membershipId)[0]->endedOn());
    }

    public function test_rejoining_does_not_reopen_an_ended_assignment(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-rejoin@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2026-01-01'));
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $assignment = $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2026-03-01'), null, '', ''));
        $service->endParticipation($membershipId, $personId, AssociationDate::fromIso('2026-09-24'));
        $service->addParticipant($membershipId, $personId, ParticipantRole::Member, false, AssociationDate::fromIso('2027-01-01'));

        self::assertSame('2026-09-24', $assignments->find((int) $assignment->id())?->endedOn()?->iso());
        self::assertCount(2, $memberships->participantsForMembership($membershipId));
    }

    public function test_ending_a_membership_still_closes_the_open_assignment(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-period@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2024-01-01'));
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $assignment = $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2024-03-01'), null, '', ''));
        $periodId = (int) $memberships->periodsForMembership($membershipId)[0]->id();

        $service->endMembership($periodId, AssociationDate::fromIso('2024-06-01'));

        self::assertSame('2024-06-01', $assignments->find((int) $assignment->id())?->endedOn()?->iso());
    }

    public function test_ending_a_membership_keeps_the_assignment_when_the_next_membership_starts_the_following_day(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-period-next@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2024-01-01'));
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $memberships->grant($personId, 'M-2', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2027-01-01'), null);
        $assignment = $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2026-03-01'), null, '', ''));

        $service->endMembership((int) $memberships->periodsForMembership($membershipId)[0]->id(), AssociationDate::fromIso('2026-12-31'));

        self::assertNull($assignments->find((int) $assignment->id())?->endedOn());
    }

    public function test_ending_a_membership_truncates_the_assignment_when_the_next_membership_leaves_a_gap(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-period-gap@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2024-01-01'));
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $memberships->grant($personId, 'M-2', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2027-01-02'), null);
        $assignment = $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2026-03-01'), null, '', ''));

        $service->endMembership((int) $memberships->periodsForMembership($membershipId)[0]->id(), AssociationDate::fromIso('2026-12-31'));

        self::assertSame('2026-12-31', $assignments->find((int) $assignment->id())?->endedOn()?->iso());
    }

    public function test_ending_a_family_membership_judges_each_person_separately(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $karinId = $service->register('Karin', 'Berg', 'karin-family@example.test', 'F-1', 'family', AssociationDate::fromIso('2024-01-01'));
        $familyId = (int) $memberships->findMembershipByNumber('F-1')?->id();
        $lisaId = $service->addPersonToMembership($familyId, 'Lisa', 'Andersson', 'lisa-family@example.test', null, AssociationDate::fromIso('2024-01-01'), ParticipantRole::Member, false);
        $memberships->grant($karinId, 'M-1', 'ordinary', MembershipStatus::Active, AssociationDate::fromIso('2027-01-01'), null);
        $karinAssignment = $assignments->add(new BoardAssignment(null, $karinId, 1, AssociationDate::fromIso('2026-03-01'), null, '', ''));
        $lisaAssignment = $assignments->add(new BoardAssignment(null, $lisaId, 1, AssociationDate::fromIso('2026-03-01'), null, '', ''));

        $service->endMembership((int) $memberships->periodsForMembership($familyId)[0]->id(), AssociationDate::fromIso('2026-12-31'));

        self::assertNull($assignments->find((int) $karinAssignment->id())?->endedOn());
        self::assertSame('2026-12-31', $assignments->find((int) $lisaAssignment->id())?->endedOn()?->iso());
    }

    public function test_ending_a_membership_with_only_a_contact_does_not_change_the_assignment(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-contact-period@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2024-01-01'));
        $contact = $memberships->addMembership(new Membership(null, 'K-1', MembershipKind::Company, 1));
        $contactPeriod = $memberships->add(new MembershipPeriod(
            null,
            (int) $contact->id(),
            MembershipStatus::Active,
            AssociationDate::fromIso('2026-01-01'),
            null,
            'ordinary'
        ));
        $service->addParticipant((int) $contact->id(), $personId, ParticipantRole::Contact, false, AssociationDate::fromIso('2026-01-01'));
        $assignment = $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2026-03-01'), null, '', ''));

        $service->endMembership((int) $contactPeriod->id(), AssociationDate::fromIso('2026-12-31'));

        self::assertNull($assignments->find((int) $assignment->id())?->endedOn());
    }

    public function test_ending_a_membership_leaves_an_assignment_that_already_ends_inside_coverage(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-period-closed@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2024-01-01'));
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $assignment = $assignments->add(new BoardAssignment(
            null,
            $personId,
            1,
            AssociationDate::fromIso('2026-01-01'),
            AssociationDate::fromIso('2026-06-30'),
            '',
            ''
        ));

        $service->endMembership((int) $memberships->periodsForMembership($membershipId)[0]->id(), AssociationDate::fromIso('2026-12-31'));

        self::assertSame('2026-06-30', $assignments->find((int) $assignment->id())?->endedOn()?->iso());
    }

    public function test_a_future_membership_end_truncates_the_assignment_on_that_date(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-period-future@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2024-01-01'));
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $assignment = $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2026-03-01'), null, '', ''));

        $service->endMembership((int) $memberships->periodsForMembership($membershipId)[0]->id(), AssociationDate::fromIso('2026-12-31'));

        self::assertSame('2026-12-31', $assignments->find((int) $assignment->id())?->endedOn()?->iso());
    }

    public function test_a_later_period_does_not_reopen_an_assignment_ended_with_the_membership(): void
    {
        [$service, $memberships, $assignments] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-period-rejoin@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2024-01-01'));
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $assignment = $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2026-03-01'), null, '', ''));
        $service->endMembership((int) $memberships->periodsForMembership($membershipId)[0]->id(), AssociationDate::fromIso('2026-12-31'));
        $service->addPeriod($membershipId, AssociationDate::fromIso('2027-06-01'));

        self::assertSame('2026-12-31', $assignments->find((int) $assignment->id())?->endedOn()?->iso());
        self::assertCount(2, $memberships->periodsForMembership($membershipId));
    }

    public function test_participation_and_period_dates_stay_independent_and_coverage_is_their_intersection(): void
    {
        [$service, $memberships] = $this->world();
        $personId = $service->register('Lisa', 'Andersson', 'lisa-hist@example.test', 'M-1', 'ordinary', AssociationDate::fromIso('2026-06-01'));
        $membershipId = (int) $memberships->findMembershipByNumber('M-1')?->id();
        $open = $memberships->participantsForMembership($membershipId)[0];
        $memberships->saveParticipant(new MembershipParticipant(
            (int) $open->id(),
            $membershipId,
            $personId,
            ParticipantRole::Member,
            true,
            AssociationDate::fromIso('2026-01-01'),
            null
        ));

        $service->endParticipation($membershipId, $personId, AssociationDate::fromIso('2026-03-01'));

        $participant = $memberships->participantsForMembership($membershipId)[0];
        $period = $memberships->periodsForMembership($membershipId)[0];
        self::assertSame('2026-03-01', $participant->endedOn()?->iso());
        self::assertSame('2026-06-01', $period->startedOn()->iso());
        self::assertFalse(MemberCoverage::isActiveMember($personId, AssociationDate::fromIso('2026-07-01'), [$participant], [$period]));

        $laterParticipant = new MembershipParticipant(
            1,
            1,
            $personId,
            ParticipantRole::Member,
            true,
            AssociationDate::fromIso('2026-01-01'),
            AssociationDate::fromIso('2027-01-01')
        );
        $endedPeriod = new MembershipPeriod(
            1,
            1,
            MembershipStatus::Ended,
            AssociationDate::fromIso('2026-01-01'),
            AssociationDate::fromIso('2026-06-01'),
            'ordinary'
        );
        self::assertTrue(MemberCoverage::isActiveMember($personId, AssociationDate::fromIso('2026-06-01'), [$laterParticipant], [$endedPeriod]));
        self::assertFalse(MemberCoverage::isActiveMember($personId, AssociationDate::fromIso('2026-06-02'), [$laterParticipant], [$endedPeriod]));
        self::assertSame('2026-06-01', MemberCoverage::retentionEnd($personId, [$laterParticipant], [$endedPeriod])?->iso());
        self::assertSame('2027-01-01', $laterParticipant->endedOn()?->iso());
    }

    public function test_only_a_member_role_satisfies_board_coverage(): void
    {
        self::assertTrue(ParticipantRole::Member->countsAsMember());
        self::assertFalse(ParticipantRole::Contact->countsAsMember());

        $on = AssociationDate::fromIso('2026-06-01');
        $period = new MembershipPeriod(1, 1, MembershipStatus::Active, AssociationDate::fromIso('2026-01-01'), null, 'ordinary');
        $contact = new MembershipParticipant(1, 1, 7, ParticipantRole::Contact, false, AssociationDate::fromIso('2026-01-01'), null);

        self::assertFalse(MemberCoverage::isActiveMember(7, $on, [$contact], [$period]));
        self::assertSame([], MemberCoverage::periodsCoveringAssignment(7, $on, null, [$contact], [$period]));
        self::assertNull(MemberCoverage::continuousCoverageFrom(7, $on, [$contact], [$period]));
    }

    public function test_structured_import_creates_non_overlapping_participation_and_writes_the_start_date(): void
    {
        [$exchange, $people, $memberships] = $this->exchange();
        $result = $exchange->import(<<<'CSV'
# foreningsplugin-members 2
membership;M-1;ordinary;
period;M-1;active;2024-01-01;;ordinary
participant;M-1;Ada;Lovelace;ada-struct@example.test;;member;1;2024-06-01;
CSV);
        $exported = $exchange->exportStructure();
        $again = $exchange->import($exported);
        $participant = $memberships->allParticipants()[0];

        self::assertSame([], $result->errors());
        self::assertSame(1, count($people->all()));
        self::assertSame('2024-06-01', $participant->startedOn()->iso());
        self::assertStringContainsString('participant;M-1;Ada;Lovelace;ada-struct@example.test;;member;1;2024-06-01;', $exported);
        self::assertSame(0, $again->created());
        self::assertSame('2024-06-01', $memberships->allParticipants()[0]->startedOn()->iso());
    }

    public function test_structured_import_rejects_overlapping_member_coverage(): void
    {
        [$exchange, $people, $memberships] = $this->exchange();
        $exchange->import(<<<'CSV'
# foreningsplugin-members 2
membership;M-1;ordinary;
period;M-1;active;2024-01-01;;ordinary
participant;M-1;Ada;Lovelace;ada-struct@example.test;;member;1;2024-01-01;
CSV);
        $before = count($memberships->allParticipants());
        $result = $exchange->import(<<<'CSV'
# foreningsplugin-members 2
membership;M-2;ordinary;
period;M-2;active;2025-01-01;;ordinary
participant;M-2;Ada;Lovelace;ada-struct@example.test;;member;1;2025-01-01;
CSV);
        $service = $this->people($people, $memberships);

        self::assertSame($before, count($memberships->allParticipants()));
        self::assertSame(['Line 4: Membership periods cannot overlap.'], $result->errors());

        try {
            $service->addParticipant(
                (int) $memberships->findMembershipByNumber('M-2')?->id(),
                (int) $people->all()[0]->id(),
                ParticipantRole::Member,
                false,
                AssociationDate::fromIso('2025-01-01')
            );
            self::fail('The application path should reject the same overlap.');
        } catch (MembershipRuleException $error) {
            self::assertSame('Membership periods cannot overlap.', $error->getMessage());
        }

        self::assertSame($before, count($memberships->allParticipants()));
    }

    public function test_structured_import_allows_a_later_interval_on_the_same_membership(): void
    {
        [$exchange, , $memberships] = $this->exchange();
        $result = $exchange->import(<<<'CSV'
# foreningsplugin-members 2
membership;M-1;ordinary;
period;M-1;ended;2020-01-01;2022-12-31;ordinary
period;M-1;active;2024-01-01;;ordinary
participant;M-1;Ada;Lovelace;ada-struct@example.test;;member;1;2020-01-01;2022-12-31
participant;M-1;Ada;Lovelace;ada-struct@example.test;;member;0;2024-01-01;
CSV);

        self::assertSame([], $result->errors());
        self::assertCount(2, $memberships->allParticipants());
        self::assertSame('2020-01-01', $memberships->allParticipants()[0]->startedOn()->iso());
        self::assertSame('2024-01-01', $memberships->allParticipants()[1]->startedOn()->iso());
    }

    public function test_a_company_contact_can_be_imported_beside_an_individual_membership(): void
    {
        [$exchange, $people, $memberships] = $this->exchange();
        $result = $exchange->import(<<<'CSV'
# foreningsplugin-members 2
organization;556012-3456;Exempel AB;;
membership;M-1;ordinary;
period;M-1;active;2024-01-01;;ordinary
participant;M-1;Ada;Lovelace;ada-struct@example.test;;member;1;2024-01-01;
membership;C-1;company;556012-3456
period;C-1;active;2024-01-01;;company
participant;C-1;Ada;Lovelace;ada-struct@example.test;;contact;1;2024-01-01;
CSV);
        $personId = (int) $people->all()[0]->id();
        $contacts = array_values(array_filter(
            $memberships->allParticipants(),
            static fn (MembershipParticipant $participant): bool => ! $participant->role()->countsAsMember()
        ));

        self::assertSame([], $result->errors());
        self::assertCount(1, $contacts);
        self::assertTrue(MemberCoverage::isActiveMember($personId, AssociationDate::fromIso('2024-06-01'), $memberships->allParticipants(), $memberships->all()));
        self::assertFalse(MemberCoverage::isActiveMember($personId, AssociationDate::fromIso('2024-06-01'), $contacts, $memberships->all()));
        self::assertSame([], MemberCoverage::periodsCoveringAssignment($personId, AssociationDate::fromIso('2024-01-01'), null, $contacts, $memberships->all()));
    }

    public function test_a_company_contact_imported_as_a_member_is_rejected(): void
    {
        [$exchange, , $memberships] = $this->exchange();
        $result = $exchange->import(<<<'CSV'
# foreningsplugin-members 2
organization;556012-3456;Exempel AB;;
membership;C-1;company;556012-3456
period;C-1;active;2024-01-01;;company
participant;C-1;Kim;Kontakt;kim-struct@example.test;;member;1;2024-01-01;
CSV);

        self::assertSame(['Line 5: A company contact is not an individual member.'], $result->errors());
        self::assertSame([], $memberships->allParticipants());
    }

    public function test_a_deceased_person_cannot_gain_membership_through_structured_import(): void
    {
        [$exchange, $people, $memberships] = $this->exchange();
        $exchange->import(<<<'CSV'
# foreningsplugin-members 2
membership;M-1;ordinary;
period;M-1;ended;2020-01-01;2022-12-31;ordinary
participant;M-1;Ada;Lovelace;ada-struct@example.test;;member;1;2020-01-01;2022-12-31
CSV);
        $person = $people->all()[0];
        $people->save($person->markedDeceased());
        $before = count($memberships->allParticipants());
        $result = $exchange->import(<<<'CSV'
# foreningsplugin-members 2
membership;M-2;ordinary;
period;M-2;active;2025-01-01;;ordinary
participant;M-2;Ada;Lovelace;ada-struct@example.test;;member;1;2025-01-01;
CSV);

        self::assertSame(['Line 4: A deceased person cannot start a membership.'], $result->errors());
        self::assertSame($before, count($memberships->allParticipants()));
    }

    public function test_a_failed_participant_row_rolls_back_the_person_it_created(): void
    {
        [$exchange, $people, $memberships] = $this->exchange(true);
        $exchange->import(<<<'CSV'
# foreningsplugin-members 2
membership;M-1;ordinary;
period;M-1;active;2024-01-01;;ordinary
participant;M-1;Ada;Lovelace;ada-struct@example.test;;member;1;2024-01-01;
CSV);
        $memberships->failNext = 'participant';

        try {
            $exchange->import(<<<'CSV'
# foreningsplugin-members 2
participant;M-1;Bea;Sen;bea-struct@example.test;;member;0;2024-06-01;
CSV);
            self::fail('A failed participant insert should leave the row transaction.');
        } catch (\RuntimeException) {
            self::assertCount(1, $people->all());
            self::assertSame('ada-struct@example.test', $people->all()[0]->email());
            self::assertCount(1, $memberships->allParticipants());
        }
    }

    public function test_a_blank_start_in_the_current_format_is_rejected_and_a_missing_column_uses_the_period(): void
    {
        [$exchange, $people, $memberships] = $this->exchange();
        $exchange->import(<<<'CSV'
# foreningsplugin-members 2
membership;M-1;ordinary;
period;M-1;active;2024-03-15;;ordinary
CSV);
        $blank = $exchange->import(<<<'CSV'
# foreningsplugin-members 2
participant;M-1;Ada;Lovelace;ada-struct@example.test;;member;1;;
CSV);
        $legacy = $exchange->import(<<<'CSV'
# foreningsplugin-members 2
participant;M-1;Bea;Sen;bea-struct@example.test;;member;1
CSV);
        $participant = $memberships->allParticipants()[0];

        self::assertSame(['Line 2: A participant needs a start date.'], $blank->errors());
        self::assertSame([], $legacy->errors());
        self::assertCount(1, $memberships->allParticipants());
        self::assertSame('2024-03-15', $participant->startedOn()->iso());
        self::assertSame('bea-struct@example.test', $people->find($participant->personId())?->email());
    }

    /**
     * @return array{0: PeopleService, 1: MemoryMembershipRepository, 2: MemoryBoardAssignmentRepository}
     */
    private function world(): array
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $assignments = new MemoryBoardAssignmentRepository();
        $service = new PeopleService(
            $people,
            $memberships,
            new MembershipLedger(),
            $this->authorizer(),
            $this->passthrough(),
            new EndOpenBoardAssignments($assignments, new BoardAssignmentLedger())
        );

        return [$service, $memberships, $assignments];
    }

    /**
     * @return array{0: MemberExchange, 1: MemoryPersonRepository, 2: MemoryMembershipRepository}
     */
    private function exchange(bool $restoring = false): array
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $organizations = new MemoryOrganizationRepository();
        $transaction = $restoring ? $this->restoring($people, $memberships) : $this->passthrough();
        $exchange = new MemberExchange(
            $people,
            $memberships,
            new MembershipLedger(),
            $this->authorizer(),
            $transaction,
            $organizations
        );

        return [$exchange, $people, $memberships];
    }

    private function people(MemoryPersonRepository $people, MemoryMembershipRepository $memberships): PeopleService
    {
        return new PeopleService(
            $people,
            $memberships,
            new MembershipLedger(),
            $this->authorizer(),
            $this->passthrough(),
            new EndOpenBoardAssignments(new MemoryBoardAssignmentRepository(), new BoardAssignmentLedger())
        );
    }

    private function authorizer(): Authorizer
    {
        return new class implements Authorizer {
            public function allows(string $capability): bool
            {
                return in_array($capability, [Capabilities::EDIT_MEMBERS, Capabilities::VIEW_MEMBERS, Capabilities::EXPORT_MEMBERS], true);
            }
        };
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

    private function restoring(MemoryPersonRepository $people, MemoryMembershipRepository $memberships): Transaction
    {
        return new class ($people, $memberships) implements Transaction {
            public function __construct(
                private MemoryPersonRepository $people,
                private MemoryMembershipRepository $memberships,
            ) {
            }

            public function run(callable $callback): mixed
            {
                $people = $this->people->people;
                $accounts = $this->memberships->accounts;
                $participants = $this->memberships->participants;
                $periods = $this->memberships->periods;

                try {
                    return $callback();
                } catch (\Throwable $error) {
                    $this->people->people = $people;
                    $this->memberships->accounts = $accounts;
                    $this->memberships->participants = $participants;
                    $this->memberships->periods = $periods;

                    throw $error;
                }
            }
        };
    }
}
