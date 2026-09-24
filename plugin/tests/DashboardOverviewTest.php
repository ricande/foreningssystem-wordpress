<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Association\DashboardOverview;
use Foreningssystem\Application\Association\DashboardSnapshot;
use Foreningssystem\Application\Association\DashboardSources;
use Foreningssystem\Application\Board\BoardDirectory;
use Foreningssystem\Application\Board\BoardSeat;
use Foreningssystem\Application\Meeting\ActionItemRow;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\CompanyMemberships;
use Foreningssystem\Application\People\PeopleService;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Meeting\ActionItem;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\Decision;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Infrastructure\WordPress\AssociationOverviewPage;
use PHPUnit\Framework\TestCase;

final class DashboardOverviewTest extends TestCase
{
    public function test_counts_follow_membership_coverage_and_hide_when_unauthorized(): void
    {
        $today = AssociationDate::fromIso('2026-09-25');
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $service = new PeopleService($people, $memberships, new MembershipLedger(), $this->authorizer(), $this->transaction(), new RecordingOpenAssignments());
        $service->register('Anna', 'Andersson', 'anna@example.test', 'M-ANNA', 'ordinary', AssociationDate::fromIso('2024-01-01'), null, $today);
        $service->register('Karin', 'Nilsson', 'karin@example.test', 'M-KARIN', 'ordinary', AssociationDate::fromIso('2024-01-01'), null, $today);
        $service->register('Johan', 'Berg', 'johan@example.test', 'M-JOHAN', 'ordinary', AssociationDate::fromIso('2024-01-01'), null, $today);
        $familyId = $service->register('Eva', 'Familj', 'eva@example.test', 'F-1', 'family', AssociationDate::fromIso('2024-01-01'), null, $today);
        $accountId = 0;

        foreach ($service->listPeople() as $record) {
            if ($record->person()->id() === $familyId) {
                $accountId = (int) $record->account()?->id();
            }
        }

        $service->addPersonToMembership($accountId, 'Nils', 'Familj', 'nils@example.test', AssociationDate::fromIso('2014-04-01'), AssociationDate::fromIso('2024-01-01'), ParticipantRole::Member, false, $today);
        $historical = $service->register('Hugo', 'Historia', 'hugo@example.test', 'M-OLD', 'ordinary', AssociationDate::fromIso('2020-01-01'), null, $today);

        foreach ($service->listPeople() as $record) {
            if ($record->person()->id() === $historical && $record->membership() !== null) {
                $service->endMembership((int) $record->membership()->id(), AssociationDate::fromIso('2024-12-31'));
            }
        }

        $service->register('Frida', 'Framtid', 'frida@example.test', 'M-FUTURE', 'ordinary', AssociationDate::fromIso('2027-01-01'), null, $today);
        $contact = $service->rememberPerson('Ekonomi', 'Kontor', 'ekonomi@example.test', null, $today);
        (new CompanyMemberships(new MemoryOrganizationRepository(), $memberships, $people, $this->authorizer(), $this->transaction()))
            ->register('Exempel AB', '', '', '', 'C-1', AssociationDate::fromIso('2026-01-01'), $contact);

        $members = $service->activeMemberCount($today);
        $membershipCount = $service->activeMembershipCount($today);
        self::assertSame(5, $members);
        self::assertSame(5, $membershipCount);

        $shown = $this->overview()->snapshot($this->sources(activeMembers: $members, activeMemberships: $membershipCount));
        self::assertSame(5, $shown->activeMembers);
        self::assertSame(5, $shown->activeMemberships);

        $hidden = $this->overview()->snapshot($this->sources(
            canViewMembers: false,
            activeMembers: $members,
            activeMemberships: $membershipCount,
        ));
        self::assertSame(0, $hidden->activeMembers);
        self::assertSame(0, $hidden->activeMemberships);
        self::assertFalse($hidden->showMembers);
    }

    public function test_current_board_follows_role_order_and_keeps_future_changes_separate(): void
    {
        $today = AssociationDate::fromIso('2026-09-25');
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $roles = new MemoryBoardRoleRepository();
        $assignments = new MemoryBoardAssignmentRepository();
        $service = new PeopleService($people, $memberships, new MembershipLedger(), $this->authorizer(), $this->transaction(), new RecordingOpenAssignments());
        $anna = $service->register('Anna', 'Andersson', 'anna@example.test', 'M-ANNA', 'ordinary', AssociationDate::fromIso('2024-01-01'), null, $today);
        $karin = $service->register('Karin', 'Nilsson', 'karin@example.test', 'M-KARIN', 'ordinary', AssociationDate::fromIso('2024-01-01'), null, $today);
        $johan = $service->register('Johan', 'Berg', 'johan@example.test', 'M-JOHAN', 'ordinary', AssociationDate::fromIso('2024-01-01'), null, $today);
        $lisa = $service->register('Lisa', 'Nilsson', 'lisa@example.test', 'M-LISA', 'ordinary', AssociationDate::fromIso('2024-01-01'), null, $today);
        $past = $service->register('Bo', 'Tidigare', 'bo@example.test', 'M-BO', 'ordinary', AssociationDate::fromIso('2020-01-01'), null, $today);
        $chair = (int) $roles->add(new BoardRole(null, 'chair', 'Chair', false, 10))->id();
        $treasurer = (int) $roles->add(new BoardRole(null, 'treasurer', 'Treasurer', false, 20))->id();
        $member = (int) $roles->add(new BoardRole(null, 'member', 'Board member', true, 40))->id();
        $assignments->add(new BoardAssignment(null, $past, $chair, AssociationDate::fromIso('2020-01-01'), AssociationDate::fromIso('2023-12-31'), '', ''));
        $assignments->add(new BoardAssignment(null, $johan, $member, AssociationDate::fromIso('2024-01-01'), null, '', ''));
        $assignments->add(new BoardAssignment(null, $karin, $treasurer, AssociationDate::fromIso('2024-01-01'), AssociationDate::fromIso('2026-12-31'), '', ''));
        $assignments->add(new BoardAssignment(null, $anna, $chair, AssociationDate::fromIso('2024-01-01'), null, '', ''));
        $assignments->add(new BoardAssignment(null, $lisa, $treasurer, AssociationDate::fromIso('2027-01-01'), null, '', ''));
        $seats = (new BoardDirectory($people, $memberships, $roles, $assignments))->seats($today);
        $current = 0;

        foreach ($seats as $seat) {
            if ($seat->state() === 'current') {
                $current++;
            }
        }

        $snapshot = $this->overview()->snapshot($this->sources(boardSeats: $seats, currentBoard: $current));
        self::assertSame(3, $snapshot->currentBoardCount);
        self::assertSame([
            ['role' => 'Chair', 'name' => 'Anna Andersson'],
            ['role' => 'Treasurer', 'name' => 'Karin Nilsson'],
            ['role' => 'Board member', 'name' => 'Johan Berg'],
        ], $snapshot->currentBoard);
        self::assertSame([
            ['role' => 'Treasurer', 'name' => 'Lisa Nilsson', 'startsOn' => '2027-01-01'],
        ], $snapshot->upcomingBoard);
        self::assertSame('board_change', $snapshot->attention[0]['kind']);
    }

    public function test_in_progress_stays_visible_and_held_is_not_finalized_minutes(): void
    {
        $now = MeetingMoment::fromLocal('2026-09-25 12:00:00');
        $running = new Meeting(1, 1, 'Board meeting — October', MeetingMoment::fromLocal('2026-09-24 18:00'), 'Hall', MeetingStatus::InProgress);
        $pastPlan = new Meeting(2, 1, 'Old plan', MeetingMoment::fromLocal('2020-02-01 18:00'), '', MeetingStatus::Planned);
        $next = new Meeting(3, 1, 'Board meeting — next week', MeetingMoment::fromLocal('2026-10-02 18:00'), 'Hall', MeetingStatus::Planned);
        $later = new Meeting(4, 1, 'Later plan', MeetingMoment::fromLocal('2026-12-01 18:00'), '', MeetingStatus::Planned);
        $finalizedMeeting = new Meeting(5, 1, 'Board meeting — August', MeetingMoment::fromLocal('2026-08-12 18:00'), '', MeetingStatus::Held);
        $awaiting = new Meeting(6, 1, 'Board meeting — September', MeetingMoment::fromLocal('2026-09-12 18:00'), '', MeetingStatus::Held);
        $draftMeeting = new Meeting(7, 1, 'Draft meeting', MeetingMoment::fromLocal('2026-07-01 18:00'), '', MeetingStatus::Held);
        $adjustmentMeeting = new Meeting(8, 1, 'Adjustment meeting', MeetingMoment::fromLocal('2026-06-01 18:00'), '', MeetingStatus::Held);
        $correctionMeeting = new Meeting(9, 1, 'Annual meeting 2026', MeetingMoment::fromLocal('2026-05-01 18:00'), '', MeetingStatus::Held);
        $revisions = [
            new MinutesRevision(1, 1, 5, 1, RevisionState::Finalized, 'August minutes', '{}', false),
            new MinutesRevision(2, 1, 7, 1, RevisionState::Draft, 'July draft', '{}', false),
            new MinutesRevision(3, 1, 8, 1, RevisionState::UnderAdjustment, 'June review', '{}', false),
            new MinutesRevision(4, 1, 9, 1, RevisionState::Finalized, 'May original', '{}', false, null, 5),
            new MinutesRevision(5, 1, 9, 2, RevisionState::Draft, 'May correction', '{}', true, 4),
        ];
        $overdue = new ActionItem(2, 6, null, 'Contact municipality', 1, AssociationDate::fromIso('2026-09-20'), ActionStatus::Open);
        $sameDayLaterId = new ActionItem(4, 6, null, 'Later same day', null, AssociationDate::fromIso('2026-09-01'), ActionStatus::Open);
        $sameDayEarlierId = new ActionItem(3, 6, null, 'Earlier same day', null, AssociationDate::fromIso('2026-09-01'), ActionStatus::Open);
        $dueToday = new ActionItem(5, 6, null, 'Due today', null, AssociationDate::fromIso('2026-09-25'), ActionStatus::Open);
        $noDate = new ActionItem(6, 6, null, 'No date', null, null, ActionStatus::Open);
        $done = new ActionItem(7, 6, null, 'Finished task', null, AssociationDate::fromIso('2026-09-01'), ActionStatus::Done);
        $openDecisions = [
            new Decision(1, 6, null, 'Keep the hall', null, null, DecisionFollowUp::Open),
            new Decision(2, 6, null, 'Buy equipment', null, null, DecisionFollowUp::Open),
            new Decision(3, 6, null, 'Already done', null, null, DecisionFollowUp::Done),
        ];
        $openDecisionCount = 0;

        foreach ($openDecisions as $decision) {
            if ($decision->followUp() === DecisionFollowUp::Open) {
                $openDecisionCount++;
            }
        }

        $snapshot = $this->overview()->snapshot($this->sources(
            meetings: [$pastPlan, $later, $running, $next, $finalizedMeeting, $awaiting, $draftMeeting, $adjustmentMeeting, $correctionMeeting],
            revisions: $revisions,
            openDecisions: $openDecisionCount,
            openActions: [
                new ActionItemRow($overdue, 'Karin Nilsson'),
                new ActionItemRow($sameDayLaterId, null),
                new ActionItemRow($sameDayEarlierId, null),
                new ActionItemRow($dueToday, null),
                new ActionItemRow($noDate, null),
                new ActionItemRow($done, null),
            ],
            boardSeats: [
                new BoardSeat(1, 1, 'Lisa Nilsson', 1, 'treasurer', 'Treasurer', 20, false, '2027-01-01', null, '', '', 'upcoming'),
            ],
            now: $now,
        ));

        self::assertSame('Board meeting — October', $snapshot->inProgress?->title());
        self::assertSame('Board meeting — next week', $snapshot->nextMeeting?->title());
        self::assertNotSame('Old plan', $snapshot->nextMeeting?->title());
        self::assertSame('Board meeting — September', $snapshot->latestHeld?->title());
        self::assertSame('none', $snapshot->latestHeldMinutes);
        self::assertSame('Board meeting — August', $snapshot->latestFinalized['title'] ?? null);
        self::assertSame(1, $snapshot->latestFinalized['number'] ?? null);
        self::assertSame(['Board meeting — September', 'Draft meeting', 'Adjustment meeting', 'Annual meeting 2026'], array_column($snapshot->minutesWork, 'title'));
        self::assertSame(['none', 'draft', 'adjustment', 'draft'], array_column($snapshot->minutesWork, 'state'));
        self::assertNotContains('Board meeting — August', array_column($snapshot->minutesWork, 'title'));
        self::assertSame(2, $snapshot->openDecisions);
        self::assertSame(5, $snapshot->openTasks);
        self::assertSame(['Earlier same day', 'Later same day', 'Contact municipality'], array_column($snapshot->overdueTasks, 'task'));
        self::assertSame('Karin Nilsson', $snapshot->overdueTasks[2]['assignee']);
        self::assertSame(['in_progress', 'overdue', 'minutes', 'minutes', 'minutes', 'minutes', 'board_change', 'next_meeting'], array_column($snapshot->attention, 'kind'));
    }

    public function test_latest_finalized_minutes_use_meeting_time_then_revision_number(): void
    {
        $earlier = new Meeting(1, 1, 'Earlier', MeetingMoment::fromLocal('2026-01-01 18:00'), '', MeetingStatus::Held);
        $laterLow = new Meeting(2, 1, 'Later low', MeetingMoment::fromLocal('2026-08-01 18:00'), '', MeetingStatus::Held);
        $laterHigh = new Meeting(3, 1, 'Later high', MeetingMoment::fromLocal('2026-08-01 18:00'), '', MeetingStatus::Held);
        $snapshot = $this->overview()->snapshot($this->sources(
            meetings: [$laterLow, $earlier, $laterHigh],
            revisions: [
                new MinutesRevision(1, 1, 1, 3, RevisionState::Finalized, 'Earlier', '{}', false),
                new MinutesRevision(2, 1, 2, 1, RevisionState::Finalized, 'Low', '{}', false),
                new MinutesRevision(3, 1, 3, 2, RevisionState::Finalized, 'High', '{}', false),
            ],
        ));

        self::assertSame('Later high', $snapshot->latestFinalized['title'] ?? null);
        self::assertSame(2, $snapshot->latestFinalized['number'] ?? null);
    }

    public function test_documents_are_bounded_by_descending_id_and_hidden_without_permission(): void
    {
        $documents = [];

        for ($id = 1; $id <= 6; $id++) {
            $documents[] = ['id' => $id, 'title' => 'Document ' . $id, 'visibility' => 'board'];
        }

        $documents[] = ['id' => 9, 'title' => 'Newest statutes', 'visibility' => 'public'];
        $shown = $this->overview()->snapshot($this->sources(documents: $documents));
        self::assertSame([9, 6, 5, 4, 3], array_column($shown->documents, 'id'));
        self::assertSame('public', $shown->documents[0]['visibility']);

        $hidden = $this->overview()->snapshot($this->sources(canViewDocuments: false, documents: $documents));
        self::assertSame([], $hidden->documents);
        self::assertFalse($hidden->showDocuments);
    }

    public function test_meeting_details_are_omitted_without_meeting_permission(): void
    {
        $meeting = new Meeting(1, 1, 'Secret meeting', MeetingMoment::fromLocal('2026-10-02 18:00'), '', MeetingStatus::Planned);
        $snapshot = $this->overview()->snapshot($this->sources(
            canViewMeetings: false,
            meetings: [$meeting],
            openDecisions: 4,
            openActions: [new ActionItemRow(new ActionItem(1, 1, null, 'Secret task', null, AssociationDate::fromIso('2020-01-01'), ActionStatus::Open), null)],
            revisions: [new MinutesRevision(1, 1, 1, 1, RevisionState::Draft, 'Secret minutes', '{}', false)],
        ));

        self::assertFalse($snapshot->showMeetings);
        self::assertNull($snapshot->nextMeeting);
        self::assertNull($snapshot->openDecisions);
        self::assertSame([], $snapshot->overdueTasks);
        self::assertSame([], $snapshot->attention);
    }

    public function test_empty_association_offers_only_permitted_setup_steps(): void
    {
        $snapshot = $this->overview()->snapshot($this->sources(
            associationName: '',
            canManageAssociation: true,
            canEditMembers: true,
            canManageMeetings: true,
        ));

        self::assertTrue($snapshot->isEmpty);
        self::assertTrue($snapshot->needsProfile);
        self::assertSame(['members', 'meeting'], $snapshot->setup);

        $viewOnly = $this->overview()->snapshot($this->sources(associationName: 'Named'));
        self::assertTrue($viewOnly->isEmpty);
        self::assertSame([], $viewOnly->setup);
        self::assertFalse($viewOnly->needsProfile);
    }

    public function test_dashboard_screen_links_instead_of_posting_mutations(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/AssociationOverviewPage.php');
        self::assertStringNotContainsString('<form', $page);
        self::assertStringNotContainsString('admin-post.php', $page);
        self::assertStringNotContainsString('storageName', $page);
        self::assertStringNotContainsString('personal_identity', $page);
        self::assertTrue(method_exists(AssociationOverviewPage::class, 'render'));
    }

    private function overview(): DashboardOverview
    {
        return new DashboardOverview();
    }

    /**
     * @param list<BoardSeat> $boardSeats
     * @param list<Meeting> $meetings
     * @param list<MinutesRevision> $revisions
     * @param list<ActionItemRow> $openActions
     * @param list<array{id: int, title: string, visibility: string}> $documents
     */
    private function sources(
        string $associationName = 'Östersunds Exempelförening',
        bool $canViewMembers = true,
        bool $canViewMeetings = true,
        bool $canManageMeetings = false,
        bool $canViewDocuments = true,
        bool $canManageAssociation = false,
        bool $canEditMembers = false,
        bool $canManageBoard = false,
        bool $canManageDocuments = false,
        int $activeMembers = 0,
        int $activeMemberships = 0,
        int $currentBoard = 0,
        array $boardSeats = [],
        array $meetings = [],
        array $revisions = [],
        int $openDecisions = 0,
        array $openActions = [],
        array $documents = [],
        ?MeetingMoment $now = null,
    ): DashboardSources {
        return new DashboardSources(
            $canViewMembers,
            $canViewMeetings,
            $canManageMeetings,
            $canViewDocuments,
            $canManageAssociation,
            $canEditMembers,
            $canManageBoard,
            $canManageDocuments,
            $associationName,
            AssociationDate::fromIso('2026-09-25'),
            $now ?? MeetingMoment::fromLocal('2026-09-25 12:00:00'),
            $activeMembers,
            $activeMemberships,
            $currentBoard,
            $boardSeats,
            $meetings,
            $revisions,
            $openDecisions,
            $openActions,
            $documents,
        );
    }

    private function authorizer(): Authorizer
    {
        return new class implements Authorizer {
            public function allows(string $capability): bool
            {
                return true;
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
