<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Board\BoardDirectory;
use Foreningssystem\Application\Board\BoardService;
use Foreningssystem\Application\Board\EndOpenBoardAssignments;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\PeopleService;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardAssignmentLedger;
use Foreningssystem\Domain\Board\BoardAssignmentRepository;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Board\BoardRoleRepository;
use Foreningssystem\Domain\Board\BoardRuleException;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use Foreningssystem\Infrastructure\Persistence\BoardSchemaMigration;
use PHPUnit\Framework\TestCase;

final class BoardServiceTest extends TestCase
{
    public function test_assignment_follows_membership_dates_and_keeps_replaced_rows(): void
    {
        [$service, $people, $memberships, $roles, $assignments] = $this->world(true);
        $ada = $this->person($people, 'Ada', 'Lovelace', 'ada@example.test');
        $grace = $this->person($people, 'Grace', 'Hopper', 'grace@example.test');
        $this->membership($memberships, $ada, 'M-1', MembershipStatus::Active, '2024-01-01', null);
        $this->membership($memberships, $grace, 'M-2', MembershipStatus::Active, '2024-01-01', null);
        $chair = $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10));
        $auditor = $roles->add(new BoardRole(null, 'auditor', 'Revisor', true, 50));

        try {
            $service->place($ada, (int) $chair->id(), AssociationDate::fromIso('2023-01-01'), null, '', '');
            self::fail('An assignment before the membership should be rejected.');
        } catch (BoardRuleException) {
            self::assertSame([], $assignments->all());
        }

        self::assertSame('saved', $service->place($ada, (int) $chair->id(), AssociationDate::fromIso('2024-01-01'), null, 'ordf@example.test', '2024'));
        self::assertSame('replaced', $service->place($grace, (int) $chair->id(), AssociationDate::fromIso('2024-03-01'), null, '', ''));

        $adaChair = $this->assignmentFor($assignments, $ada, (int) $chair->id());
        $graceChair = $this->assignmentFor($assignments, $grace, (int) $chair->id());
        self::assertSame('2024-02-29', $adaChair->endedOn()?->iso());
        self::assertSame('ordf@example.test', $adaChair->publicContact());
        self::assertNotSame('ada@example.test', $adaChair->publicContact());
        self::assertNull($graceChair->endedOn());

        $service->place($ada, (int) $auditor->id(), AssociationDate::fromIso('2024-01-01'), null, '', '');
        $service->place($grace, (int) $auditor->id(), AssociationDate::fromIso('2024-01-01'), null, '', '');
        self::assertCount(4, $assignments->all());

        $current = array_values(array_filter(
            $service->history(AssociationDate::fromIso('2024-06-01')),
            static fn ($post): bool => $post->current()
        ));
        self::assertCount(3, $current);

        foreach ($service->history(AssociationDate::fromIso('2024-06-01')) as $post) {
            self::assertStringNotContainsString('@', $post->personName());
        }
    }

    public function test_only_active_or_ended_membership_dates_cover_an_assignment(): void
    {
        [$service, $people, $memberships, $roles] = $this->world(true);
        $personId = $this->person($people, 'Ada', 'Lovelace', 'ada@example.test');
        $roleId = (int) $roles->add(new BoardRole(null, 'secretary', 'Sekreterare', false, 30))->id();
        $memberships->grant($personId, 'M-1', 'ordinarie', MembershipStatus::Dormant, AssociationDate::fromIso('2024-01-01'), null);

        try {
            $service->place($personId, $roleId, AssociationDate::fromIso('2024-02-01'), null, '', '');
            self::fail('A dormant membership should not cover a board assignment.');
        } catch (BoardRuleException) {
        }

        $existing = $memberships->find(1);
        self::assertInstanceOf(MembershipPeriod::class, $existing);
        $memberships->save(new MembershipPeriod(
            $existing->id(),
            $existing->membershipId(),
            MembershipStatus::Ended,
            $existing->startedOn(),
            AssociationDate::fromIso('2024-06-01'),
            $existing->historicalClass()
        ));
        $service->place($personId, $roleId, AssociationDate::fromIso('2024-01-01'), AssociationDate::fromIso('2024-06-01'), '', '');

        try {
            $service->place($personId, $roleId, AssociationDate::fromIso('2024-01-01'), null, '', '');
            self::fail('An ended membership should not cover an open assignment.');
        } catch (BoardRuleException) {
        }
    }

    public function test_a_deceased_person_is_not_on_the_current_board(): void
    {
        [$service, $people, $memberships, $roles] = $this->world(true);
        $person = $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null));
        $personId = (int) $person->id();
        $this->membership($memberships, $personId, 'M-1', MembershipStatus::Active, '2024-01-01', null);
        $roleId = (int) $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10))->id();
        $service->place($personId, $roleId, AssociationDate::fromIso('2024-01-01'), null, '', '');
        $people->save($person->markedDeceased());

        self::assertFalse($service->history(AssociationDate::fromIso('2024-06-01'))[0]->current());

        try {
            $service->place($personId, $roleId, AssociationDate::fromIso('2024-07-01'), null, '', '');
            self::fail('A deceased person should not receive an open assignment.');
        } catch (BoardRuleException) {
        }
    }

    public function test_editing_the_board_requires_manage_board(): void
    {
        [$service] = $this->world(false);

        $this->expectException(NotAllowed::class);
        $service->place(1, 1, AssociationDate::fromIso('2024-01-01'), null, '', '');
    }

    public function test_ending_a_membership_ends_open_assignments_on_the_same_day(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $assignments = new MemoryBoardAssignmentRepository();
        $personId = (int) $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null))->id();
        $period = $this->membership($memberships, $personId, 'M-1', MembershipStatus::Active, '2024-01-01', null);
        $assignments->add(new BoardAssignment(null, $personId, 1, AssociationDate::fromIso('2024-01-01'), null, 'ordf@example.test', ''));
        $assignments->add(new BoardAssignment(null, $personId, 2, AssociationDate::fromIso('2023-01-01'), AssociationDate::fromIso('2023-12-31'), '', ''));
        $service = new PeopleService($people, $memberships, new MembershipLedger(), $this->authorizer(true), $this->transaction(), new EndOpenBoardAssignments($assignments, new BoardAssignmentLedger()));

        $service->endMembership((int) $period->id(), AssociationDate::fromIso('2024-06-01'));

        self::assertSame('2024-06-01', $this->assignmentFor($assignments, $personId, 1)->endedOn()?->iso());
        self::assertSame('2023-12-31', $this->assignmentFor($assignments, $personId, 2)->endedOn()?->iso());
        self::assertSame('ordf@example.test', $this->assignmentFor($assignments, $personId, 1)->publicContact());
    }

    public function test_schema_migration_creates_board_tables_and_suggested_roles(): void
    {
        $migration = new BoardSchemaMigration('wp_', 'DEFAULT CHARSET utf8mb4');
        $sql = $migration->statements();
        $multiple = [];

        foreach ($migration->suggestedRoles() as $role) {
            $multiple[$role['slug']] = $role['allows_multiple'];
        }

        self::assertSame(3, $migration->version());
        self::assertStringContainsString('CREATE TABLE wp_assoc_board_role', $sql);
        self::assertStringContainsString('CREATE TABLE wp_assoc_board_assignment', $sql);
        self::assertStringNotContainsString('wp_users', $sql);
        self::assertSame(0, $multiple['chair']);
        self::assertSame(1, $multiple['auditor']);
        self::assertSame(1, $multiple['election_committee']);
    }

    public function test_board_assignment_must_fall_inside_the_participation_interval(): void
    {
        [$service, $people, $memberships, $roles] = $this->world(true);
        $personId = $this->person($people, 'Lisa', 'Andersson', 'lisa-board@example.test');
        $period = $this->membership($memberships, $personId, 'F-BOARD', MembershipStatus::Active, '2024-01-01', null);
        $membershipId = $period->membershipId();
        $existing = $memberships->participantsForMembership($membershipId)[0];
        $memberships->saveParticipant($existing->ended(AssociationDate::fromIso('2024-12-31')));
        $memberships->addParticipant(new \Foreningssystem\Domain\Membership\MembershipParticipant(
            null,
            $membershipId,
            $personId,
            \Foreningssystem\Domain\Membership\ParticipantRole::Member,
            false,
            AssociationDate::fromIso('2026-09-24'),
            null
        ));
        $roleId = (int) $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10))->id();

        try {
            $service->place($personId, $roleId, AssociationDate::fromIso('2025-06-01'), AssociationDate::fromIso('2025-12-31'), '', '');
            self::fail('An assignment before the participation starts should be rejected.');
        } catch (BoardRuleException) {
            self::assertTrue(true);
        }

        self::assertSame('saved', $service->place($personId, $roleId, AssociationDate::fromIso('2026-09-24'), null, '', ''));
    }

    public function test_continuous_membership_covers_an_assignment_and_a_gap_does_not(): void
    {
        [$service, $people, $memberships, $roles, $assignments] = $this->world(true);
        $covered = $this->person($people, 'Karin', 'Andersson', 'karin-cover@example.test');
        $gapped = $this->person($people, 'Johan', 'Berg', 'johan-gap@example.test');
        $this->membership($memberships, $covered, 'M-COVER-A', MembershipStatus::Active, '2025-01-01', '2026-12-31');
        $this->membership($memberships, $covered, 'M-COVER-B', MembershipStatus::Active, '2027-01-01', null);
        $this->membership($memberships, $gapped, 'M-GAP-A', MembershipStatus::Active, '2025-01-01', '2026-12-31');
        $this->membership($memberships, $gapped, 'M-GAP-B', MembershipStatus::Active, '2027-01-02', null);
        $roleId = (int) $roles->add(new BoardRole(null, 'treasurer', 'Kassör', false, 20))->id();

        try {
            $service->place($gapped, $roleId, AssociationDate::fromIso('2026-06-01'), null, '', '');
            self::fail('A gap in membership should reject the assignment.');
        } catch (BoardRuleException $error) {
            self::assertSame('The membership does not cover this assignment.', $error->getMessage());
        }

        self::assertSame('saved', $service->place($covered, $roleId, AssociationDate::fromIso('2026-06-01'), null, 'kassor@example.test', '2026–2027'));

        self::assertCount(1, $assignments->all());
        self::assertSame('kassor@example.test', $assignments->all()[0]->publicContact());
        self::assertNotSame('karin-cover@example.test', $assignments->all()[0]->publicContact());
    }

    public function test_a_failed_or_backdated_replacement_leaves_the_open_assignment(): void
    {
        [$service, $people, $memberships, $roles, $assignments] = $this->world(true);
        $anna = $this->person($people, 'Anna', 'Andersson', 'anna-board@example.test');
        $karin = $this->person($people, 'Karin', 'Nilsson', 'karin-board@example.test');
        $outsider = $this->person($people, 'Erik', 'Berg', 'erik-board@example.test');
        $this->membership($memberships, $anna, 'M-ANNA', MembershipStatus::Active, '2024-01-01', null);
        $this->membership($memberships, $karin, 'M-KARIN', MembershipStatus::Active, '2024-01-01', null);
        $roleId = (int) $roles->add(new BoardRole(null, 'treasurer', 'Kassör', false, 20))->id();
        $service->place($anna, $roleId, AssociationDate::fromIso('2026-03-01'), null, 'anna@example.test', '');

        try {
            $service->place($outsider, $roleId, AssociationDate::fromIso('2026-06-01'), null, '', '');
            self::fail('A person without membership should not replace the treasurer.');
        } catch (BoardRuleException) {
            self::assertTrue(true);
        }

        $annaAssignment = $this->assignmentFor($assignments, $anna, $roleId);
        self::assertNull($annaAssignment->endedOn());
        self::assertSame('2026-03-01', $annaAssignment->startedOn()->iso());

        try {
            $service->place($karin, $roleId, AssociationDate::fromIso('2025-01-01'), null, '', '', AssociationDate::fromIso('2026-09-24'));
            self::fail('A replacement cannot start before the open assignment.');
        } catch (BoardRuleException $error) {
            self::assertSame('This role already has a holder for those dates.', $error->getMessage());
        }

        $annaAssignment = $this->assignmentFor($assignments, $anna, $roleId);
        self::assertNull($annaAssignment->endedOn());
        self::assertSame('2026-03-01', $annaAssignment->startedOn()->iso());
        self::assertCount(1, $assignments->all());
    }

    public function test_directory_separates_current_upcoming_and_history(): void
    {
        [$service, $people, $memberships, $roles, $assignments] = $this->world(true);
        $anna = $this->person($people, 'Anna', 'Andersson', 'anna-dir@example.test');
        $karin = $this->person($people, 'Karin', 'Nilsson', 'karin-dir@example.test');
        $lisa = $this->person($people, 'Lisa', 'Nilsson', 'lisa-dir@example.test');
        $johan = $this->person($people, 'Johan', 'Berg', 'johan-dir@example.test');
        $this->membership($memberships, $anna, 'M-DIR-ANNA', MembershipStatus::Active, '2024-01-01', null);
        $this->membership($memberships, $karin, 'M-DIR-KARIN', MembershipStatus::Active, '2024-01-01', null);
        $this->membership($memberships, $lisa, 'M-DIR-LISA', MembershipStatus::Active, '2024-01-01', null);
        $this->membership($memberships, $johan, 'M-DIR-JOHAN', MembershipStatus::Active, '2024-01-01', null);
        $treasurer = (int) $roles->add(new BoardRole(null, 'treasurer', 'Kassör', false, 20))->id();
        $chair = (int) $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10))->id();
        $alternate = (int) $roles->add(new BoardRole(null, 'alternate', 'Suppleant', true, 40))->id();
        $service->place($anna, $treasurer, AssociationDate::fromIso('2024-03-12'), null, '', '2024');
        $service->place($karin, $treasurer, AssociationDate::fromIso('2027-01-01'), null, 'kassor@example.test', '2027');
        $service->place($lisa, $chair, AssociationDate::fromIso('2026-01-01'), null, '', '');
        $service->place($lisa, $alternate, AssociationDate::fromIso('2026-01-01'), null, '', '');
        $service->place($johan, $alternate, AssociationDate::fromIso('2026-02-01'), null, '', '');
        $directory = new BoardDirectory($people, $memberships, $roles, $assignments);
        $today = AssociationDate::fromIso('2026-09-24');
        $current = [];
        $upcoming = [];
        $history = [];

        foreach ($directory->seats($today) as $seat) {
            if ($seat->state() === 'current') {
                $current[] = $seat;
            } elseif ($seat->state() === 'upcoming') {
                $upcoming[] = $seat;
            } else {
                $history[] = $seat;
            }
        }

        self::assertSame(['chair', 'treasurer', 'alternate', 'alternate'], array_map(static fn ($seat) => $seat->roleSlug(), $current));
        self::assertSame(['Lisa Nilsson', 'Anna Andersson', 'Johan Berg', 'Lisa Nilsson'], array_map(static fn ($seat) => $seat->personName(), $current));
        self::assertSame('2026-12-31', $current[1]->endedOn());
        self::assertSame(['Karin Nilsson'], array_map(static fn ($seat) => $seat->personName(), $upcoming));
        self::assertSame('treasurer', $upcoming[0]->roleSlug());
        self::assertSame([], $history);
        self::assertSame('active', $directory->people($today)[0]['coverage']);

        $public = $service->currentPublic($today);
        $names = array_map(static fn ($seat) => $seat->personName(), $public);
        self::assertContains('Anna Andersson', $names);
        self::assertNotContains('Karin Nilsson', $names);
        $later = $service->currentPublic(AssociationDate::fromIso('2027-01-01'));
        $laterNames = array_map(static fn ($seat) => $seat->personName(), $later);
        self::assertContains('Karin Nilsson', $laterNames);
        self::assertNotContains('Anna Andersson', $laterNames);

        foreach ($later as $seat) {
            if ($seat->personName() === 'Karin Nilsson') {
                self::assertSame('kassor@example.test', $seat->publicContact());
                self::assertStringNotContainsString('karin-dir@example.test', $seat->publicContact());
            }
        }
    }

    public function test_ending_an_assignment_keeps_the_row_and_rejects_a_bad_end(): void
    {
        [$service, $people, $memberships, $roles, $assignments] = $this->world(true);
        $personId = $this->person($people, 'Karin', 'Andersson', 'karin-end@example.test');
        $this->membership($memberships, $personId, 'M-END', MembershipStatus::Active, '2024-01-01', null);
        $roleId = (int) $roles->add(new BoardRole(null, 'secretary', 'Sekreterare', false, 30))->id();
        $service->place($personId, $roleId, AssociationDate::fromIso('2026-03-10'), null, '', '');
        $assignmentId = (int) $this->assignmentFor($assignments, $personId, $roleId)->id();
        $service->end($assignmentId, AssociationDate::fromIso('2026-09-24'));
        $ended = $this->assignmentFor($assignments, $personId, $roleId);
        self::assertSame('2026-09-24', $ended->endedOn()?->iso());
        self::assertSame('2026-03-10', $ended->startedOn()->iso());

        try {
            $service->end($assignmentId, AssociationDate::fromIso('2026-10-01'));
            self::fail('An ended assignment should stay ended.');
        } catch (BoardRuleException $error) {
            self::assertSame('The assignment is already ended.', $error->getMessage());
        }

        $service->place($personId, $roleId, AssociationDate::fromIso('2026-10-01'), null, '', '');
        $open = null;

        foreach ($assignments->all() as $assignment) {
            if ($assignment->endedOn() === null) {
                $open = $assignment;
            }
        }

        self::assertNotNull($open);

        try {
            $service->end((int) $open->id(), AssociationDate::fromIso('2026-09-01'));
            self::fail('An assignment cannot end before it starts.');
        } catch (BoardRuleException) {
            self::assertNull($open->endedOn());
        }

        self::assertCount(2, $assignments->all());
    }

    public function test_invalid_board_targets_are_rejected(): void
    {
        [$service, $people, $memberships, $roles] = $this->world(true);
        $personId = $this->person($people, 'Anna', 'Andersson', 'anna-invalid@example.test');
        $this->membership($memberships, $personId, 'M-INVALID', MembershipStatus::Active, '2024-01-01', null);
        $roleId = (int) $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10))->id();

        try {
            $service->place(999, $roleId, AssociationDate::fromIso('2026-01-01'), null, '', '');
            self::fail('An unknown person should be rejected.');
        } catch (\RuntimeException $error) {
            self::assertSame('Person was not found.', $error->getMessage());
        }

        try {
            $service->place($personId, 999, AssociationDate::fromIso('2026-01-01'), null, '', '');
            self::fail('An unknown role should be rejected.');
        } catch (\RuntimeException $error) {
            self::assertSame('Role was not found.', $error->getMessage());
        }

        try {
            $service->end(999, AssociationDate::fromIso('2026-01-01'));
            self::fail('An unknown assignment should be rejected.');
        } catch (\RuntimeException $error) {
            self::assertSame('Assignment was not found.', $error->getMessage());
        }
    }

    public function test_upcoming_dates_are_not_described_as_present(): void
    {
        $open = $this->seat('upcoming', '2027-01-01', null);
        $fixed = $this->seat('upcoming', '2027-01-01', '2028-03-10');
        $current = $this->seat('current', '2026-03-10', null);

        self::assertSame('starts', $open->datePresentation());
        self::assertSame('range', $fixed->datePresentation());
        self::assertSame('since', $current->datePresentation());
        self::assertNotSame('present', $open->datePresentation());
    }

    public function test_a_current_holder_with_a_planned_end_can_be_replaced_before_that_end(): void
    {
        [$service, $people, $memberships, $roles, $assignments] = $this->world(true);
        $today = AssociationDate::fromIso('2026-09-24');
        $anna = $this->person($people, 'Anna', 'Andersson', 'anna-fixed@example.test');
        $lisa = $this->person($people, 'Lisa', 'Nilsson', 'lisa-fixed@example.test');
        $this->membership($memberships, $anna, 'M-FIXED-ANNA', MembershipStatus::Active, '2024-01-01', null);
        $this->membership($memberships, $lisa, 'M-FIXED-LISA', MembershipStatus::Active, '2024-01-01', null);
        $roleId = (int) $roles->add(new BoardRole(null, 'treasurer', 'Kassör', false, 20))->id();
        $service->place($anna, $roleId, AssociationDate::fromIso('2026-01-01'), AssociationDate::fromIso('2027-03-31'), '', '', $today);

        self::assertSame('replaced', $service->place($lisa, $roleId, AssociationDate::fromIso('2027-02-01'), null, '', '', $today));

        $annaAssignment = $this->assignmentFor($assignments, $anna, $roleId);
        $lisaAssignment = $this->assignmentFor($assignments, $lisa, $roleId);
        self::assertSame('2027-01-31', $annaAssignment->endedOn()?->iso());
        self::assertSame('2026-01-01', $annaAssignment->startedOn()->iso());
        self::assertSame('2027-02-01', $lisaAssignment->startedOn()->iso());
        self::assertNull($lisaAssignment->endedOn());
    }

    public function test_a_later_start_does_not_extend_the_current_holder(): void
    {
        [$service, $people, $memberships, $roles, $assignments] = $this->world(true);
        $today = AssociationDate::fromIso('2026-09-24');
        $anna = $this->person($people, 'Anna', 'Andersson', 'anna-gap@example.test');
        $lisa = $this->person($people, 'Lisa', 'Nilsson', 'lisa-gap@example.test');
        $this->membership($memberships, $anna, 'M-GAP-ANNA', MembershipStatus::Active, '2024-01-01', null);
        $this->membership($memberships, $lisa, 'M-GAP-LISA', MembershipStatus::Active, '2024-01-01', null);
        $roleId = (int) $roles->add(new BoardRole(null, 'treasurer', 'Kassör', false, 20))->id();
        $service->place($anna, $roleId, AssociationDate::fromIso('2026-01-01'), AssociationDate::fromIso('2026-12-31'), '', '', $today);
        self::assertSame('saved', $service->place($lisa, $roleId, AssociationDate::fromIso('2027-02-01'), null, '', '', $today));
        self::assertSame('2026-12-31', $this->assignmentFor($assignments, $anna, $roleId)->endedOn()?->iso());
    }

    public function test_a_scheduled_successor_blocks_another_until_it_is_cancelled(): void
    {
        [$service, $people, $memberships, $roles, $assignments] = $this->world(true);
        $today = AssociationDate::fromIso('2026-09-24');
        $anna = $this->person($people, 'Anna', 'Andersson', 'anna-plan@example.test');
        $karin = $this->person($people, 'Karin', 'Nilsson', 'karin-plan@example.test');
        $lisa = $this->person($people, 'Lisa', 'Nilsson', 'lisa-plan@example.test');
        $this->membership($memberships, $anna, 'M-PLAN-ANNA', MembershipStatus::Active, '2024-01-01', null);
        $this->membership($memberships, $karin, 'M-PLAN-KARIN', MembershipStatus::Active, '2024-01-01', null);
        $this->membership($memberships, $lisa, 'M-PLAN-LISA', MembershipStatus::Active, '2024-01-01', null);
        $roleId = (int) $roles->add(new BoardRole(null, 'treasurer', 'Kassör', false, 20))->id();
        $service->place($anna, $roleId, AssociationDate::fromIso('2025-03-10'), null, 'anna-public@example.test', '', $today);
        $service->place($karin, $roleId, AssociationDate::fromIso('2027-01-01'), null, 'karin-public@example.test', '', $today);

        self::assertSame('2026-12-31', $this->assignmentFor($assignments, $anna, $roleId)->endedOn()?->iso());
        $karinAssignment = $this->assignmentFor($assignments, $karin, $roleId);

        foreach (['2026-11-01', '2027-01-01', '2027-02-01'] as $start) {
            try {
                $service->place($lisa, $roleId, AssociationDate::fromIso($start), null, '', '', $today);
                self::fail('A second scheduled successor should be rejected.');
            } catch (BoardRuleException $error) {
                self::assertSame('This role already has a scheduled assignment.', $error->getMessage());
            }

            $karinRow = $this->assignmentFor($assignments, $karin, $roleId);
            self::assertSame('2027-01-01', $karinRow->startedOn()->iso());
            self::assertNull($karinRow->endedOn());
            self::assertSame('2026-12-31', $this->assignmentFor($assignments, $anna, $roleId)->endedOn()?->iso());
            self::assertCount(2, $assignments->all());
        }

        $cancelled = $service->cancelScheduled((int) $karinAssignment->id(), $today);
        self::assertSame('2026-12-31', $cancelled->currentEnd());
        self::assertSame('2026-12-31', $this->assignmentFor($assignments, $anna, $roleId)->endedOn()?->iso());
        self::assertNull($assignments->find((int) $karinAssignment->id()));

        $publicToday = $service->currentPublic($today);
        $publicLater = $service->currentPublic(AssociationDate::fromIso('2027-01-01'));
        $todayNames = array_map(static fn ($seat) => $seat->personName(), $publicToday);
        $laterNames = array_map(static fn ($seat) => $seat->personName(), $publicLater);
        self::assertContains('Anna Andersson', $todayNames);
        self::assertNotContains('Karin Nilsson', $todayNames);
        self::assertNotContains('Karin Nilsson', $laterNames);
        self::assertSame('anna-public@example.test', $publicToday[0]->publicContact());

        try {
            $service->cancelScheduled((int) $this->assignmentFor($assignments, $anna, $roleId)->id(), $today);
            self::fail('The current assignment should not be cancelled as a scheduled one.');
        } catch (BoardRuleException $error) {
            self::assertSame('Only a scheduled assignment that has not started can be cancelled.', $error->getMessage());
        }

        self::assertNotNull($assignments->find((int) $this->assignmentFor($assignments, $anna, $roleId)->id()));

        self::assertSame('saved', $service->place($lisa, $roleId, AssociationDate::fromIso('2027-02-01'), null, '', '', $today));
        $lisaAssignment = $this->assignmentFor($assignments, $lisa, $roleId);
        self::assertSame('2027-02-01', $lisaAssignment->startedOn()->iso());
        self::assertNull($lisaAssignment->endedOn());
        self::assertSame('2026-12-31', $this->assignmentFor($assignments, $anna, $roleId)->endedOn()?->iso());
    }

    public function test_cancellation_rejects_history_today_and_a_failed_delete(): void
    {
        [$service, $people, $memberships, $roles, $assignments] = $this->world(true);
        $today = AssociationDate::fromIso('2026-09-24');
        $anna = $this->person($people, 'Anna', 'Andersson', 'anna-cancel@example.test');
        $past = $this->person($people, 'Erik', 'Berg', 'erik-cancel@example.test');
        $this->membership($memberships, $anna, 'M-CANCEL-ANNA', MembershipStatus::Active, '2024-01-01', null);
        $this->membership($memberships, $past, 'M-CANCEL-ERIK', MembershipStatus::Active, '2019-01-01', null);
        $roleId = (int) $roles->add(new BoardRole(null, 'treasurer', 'Kassör', false, 20))->id();
        $service->place($past, $roleId, AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('2020-12-31'), '', '', $today);
        $service->place($anna, $roleId, AssociationDate::fromIso('2026-09-24'), null, '', '', $today);
        $historyId = (int) $this->assignmentFor($assignments, $past, $roleId)->id();
        $todayId = (int) $this->assignmentFor($assignments, $anna, $roleId)->id();

        try {
            $service->cancelScheduled($historyId, $today);
            self::fail('A historical assignment should not be cancelled.');
        } catch (BoardRuleException) {
            self::assertSame('2020-12-31', $assignments->find($historyId)?->endedOn()?->iso());
        }

        try {
            $service->cancelScheduled($todayId, $today);
            self::fail('An assignment that starts today is not a future assignment.');
        } catch (BoardRuleException) {
            self::assertNull($assignments->find($todayId)?->endedOn());
        }

        try {
            $service->cancelScheduled(999, $today);
            self::fail('An unknown assignment should be rejected.');
        } catch (\RuntimeException $error) {
            self::assertSame('Assignment was not found.', $error->getMessage());
        }

        $future = $this->person($people, 'Karin', 'Nilsson', 'karin-cancel@example.test');
        $this->membership($memberships, $future, 'M-CANCEL-KARIN', MembershipStatus::Active, '2024-01-01', null);
        $service->place($future, $roleId, AssociationDate::fromIso('2027-01-01'), null, '', '', $today);
        $futureId = (int) $this->assignmentFor($assignments, $future, $roleId)->id();
        $assignments->failRemove = true;

        try {
            $service->cancelScheduled($futureId, $today);
            self::fail('A failed delete should not be reported as a cancellation.');
        } catch (\RuntimeException $error) {
            self::assertSame('The assignment could not be removed.', $error->getMessage());
        }

        self::assertNotNull($assignments->find($futureId));
        self::assertSame('2026-12-31', $this->assignmentFor($assignments, $anna, $roleId)->endedOn()?->iso());
    }

    public function test_cancelling_a_scheduled_assignment_requires_manage_board(): void
    {
        [$service] = $this->world(false);

        $this->expectException(NotAllowed::class);
        $service->cancelScheduled(1, AssociationDate::fromIso('2026-09-24'));
    }

    public function test_a_historical_assignment_is_not_rewritten_by_a_later_candidate(): void
    {
        [$service, $people, $memberships, $roles, $assignments] = $this->world(true);
        $today = AssociationDate::fromIso('2026-09-24');
        $anna = $this->person($people, 'Anna', 'Andersson', 'anna-history@example.test');
        $lisa = $this->person($people, 'Lisa', 'Nilsson', 'lisa-history@example.test');
        $this->membership($memberships, $anna, 'M-HIST-ANNA', MembershipStatus::Active, '2019-01-01', '2020-12-31');
        $this->membership($memberships, $lisa, 'M-HIST-LISA', MembershipStatus::Active, '2019-01-01', null);
        $roleId = (int) $roles->add(new BoardRole(null, 'treasurer', 'Kassör', false, 20))->id();
        $service->place($anna, $roleId, AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('2020-12-31'), '', '', $today);

        try {
            $service->place($lisa, $roleId, AssociationDate::fromIso('2020-06-01'), null, '', '', $today);
            self::fail('A new assignment should not rewrite a historical row.');
        } catch (BoardRuleException) {
            self::assertSame('2020-12-31', $this->assignmentFor($assignments, $anna, $roleId)->endedOn()?->iso());
        }

        self::assertCount(1, $assignments->all());
    }

    private function seat(string $state, string $startedOn, ?string $endedOn): \Foreningssystem\Application\Board\BoardSeat
    {
        return new \Foreningssystem\Application\Board\BoardSeat(
            1,
            1,
            'Karin Nilsson',
            1,
            'treasurer',
            'Kassör',
            20,
            false,
            $startedOn,
            $endedOn,
            '',
            '',
            $state
        );
    }

    /**
     * @return array{0: BoardService, 1: MemoryPersonRepository, 2: MemoryMembershipRepository, 3: MemoryBoardRoleRepository, 4: MemoryBoardAssignmentRepository}
     */
    private function world(bool $allowed): array
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $roles = new MemoryBoardRoleRepository();
        $assignments = new MemoryBoardAssignmentRepository();
        $service = new BoardService($people, $memberships, $roles, $assignments, new BoardAssignmentLedger(), $this->authorizer($allowed), $this->transaction());

        return [$service, $people, $memberships, $roles, $assignments];
    }

    private function person(MemoryPersonRepository $people, string $firstName, string $lastName, string $email): int
    {
        return (int) $people->add(new Person(null, $firstName, $lastName, $email, PersonStatus::Known, null))->id();
    }

    private function membership(MemoryMembershipRepository $memberships, int $personId, string $number, MembershipStatus $status, string $startedOn, ?string $endedOn): MembershipPeriod
    {
        return $memberships->grant(
            $personId,
            $number,
            'ordinarie',
            $status,
            AssociationDate::fromIso($startedOn),
            $endedOn === null ? null : AssociationDate::fromIso($endedOn)
        );
    }

    private function assignmentFor(MemoryBoardAssignmentRepository $assignments, int $personId, int $roleId): BoardAssignment
    {
        foreach ($assignments->all() as $assignment) {
            if ($assignment->personId() === $personId && $assignment->roleId() === $roleId) {
                return $assignment;
            }
        }

        self::fail('Assignment was not found.');
    }

    private function authorizer(bool $allowed): Authorizer
    {
        return new class ($allowed) implements Authorizer {
            public function __construct(private bool $allowed)
            {
            }

            public function allows(string $capability): bool
            {
                if (! $this->allowed) {
                    return false;
                }

                return in_array($capability, [Capabilities::MANAGE_BOARD, Capabilities::VIEW_MEMBERS, Capabilities::EDIT_MEMBERS], true);
            }
        };
    }

    private function transaction(): Transaction
    {
        return new class implements Transaction {
            public function run(callable $callback): mixed
            {
                return $callback();
            }
        };
    }
}

final class MemoryBoardRoleRepository implements BoardRoleRepository
{
    /** @var array<int, BoardRole> */
    public array $roles = [];

    private int $nextId = 1;

    public bool $failNextWrite = false;

    public function add(BoardRole $role): BoardRole
    {
        $this->guardWrite();
        $saved = $role->withId($this->nextId);
        $this->roles[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function save(BoardRole $role): void
    {
        $this->guardWrite();
        $id = $role->id();

        if ($id === null || ! isset($this->roles[$id])) {
            throw new \RuntimeException('The board role could not be saved.');
        }

        $this->roles[$id] = $role;
    }

    private function guardWrite(): void
    {
        if (! $this->failNextWrite) {
            return;
        }

        $this->failNextWrite = false;

        throw new \RuntimeException('The board role could not be saved.');
    }

    public function find(int $id): ?BoardRole
    {
        return $this->roles[$id] ?? null;
    }

    public function findBySlug(string $slug): ?BoardRole
    {
        foreach ($this->roles as $role) {
            if ($role->slug() === $slug) {
                return $role;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_values($this->roles);
    }
}

final class MemoryBoardAssignmentRepository implements BoardAssignmentRepository
{
    /** @var array<int, BoardAssignment> */
    public array $assignments = [];

    private int $nextId = 1;

    public function add(BoardAssignment $assignment): BoardAssignment
    {
        $saved = $assignment->withId($this->nextId);
        $this->assignments[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function save(BoardAssignment $assignment): void
    {
        $id = $assignment->id();

        if ($id === null || ! isset($this->assignments[$id])) {
            throw new \RuntimeException('Assignment was not found.');
        }

        $this->assignments[$id] = $assignment;
    }

    public bool $failRemove = false;

    public function remove(int $id): void
    {
        if ($this->failRemove || ! isset($this->assignments[$id])) {
            throw new \RuntimeException('The assignment could not be removed.');
        }

        unset($this->assignments[$id]);
    }

    public function find(int $id): ?BoardAssignment
    {
        return $this->assignments[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->assignments);
    }
}
