<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Decision\AgendaPresentation;
use Foreningssystem\Application\Decision\DecisionRegister;
use Foreningssystem\Application\Decision\DecisionRegisterQuery;
use Foreningssystem\Application\Decision\DecisionRegisterSnapshot;
use Foreningssystem\Application\Decision\ResponsiblePresentation;
use Foreningssystem\Application\Meeting\MeetingRecord;
use Foreningssystem\Application\Meeting\MeetingService;
use Foreningssystem\Application\Meeting\MinutesComposer;
use Foreningssystem\Application\Meeting\MinutesDrafts;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\AgendaItem;
use Foreningssystem\Domain\Meeting\AgendaOrder;
use Foreningssystem\Domain\Meeting\Decision;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingLifecycle;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MeetingType;
use Foreningssystem\Domain\Meeting\MinutesLifecycle;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use Foreningssystem\Infrastructure\WordPress\DecisionsPage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DecisionRegisterTest extends TestCase
{
    private const TODAY = '2026-09-25';

    public function test_viewing_internal_meetings_can_read_the_register(): void
    {
        $snapshot = $this->sample()['register']->snapshot($this->today(), DecisionRegisterQuery::defaults());

        self::assertGreaterThan(0, $snapshot->openCount + $snapshot->doneCount);
    }

    public function test_a_user_without_internal_meetings_cannot_read_the_register(): void
    {
        $world = $this->sample();

        try {
            $world['hidden']->snapshot($this->today(), DecisionRegisterQuery::defaults());
            self::fail('The register should require view_internal_meetings.');
        } catch (NotAllowed $error) {
            self::assertSame(Capabilities::VIEW_INTERNAL_MEETINGS, $error->getMessage());
        }
    }

    public function test_every_stored_decision_keeps_its_identity_and_source(): void
    {
        $world = $this->sample();
        $snapshot = $world['register']->snapshot($this->today(), DecisionRegisterQuery::normalize('all', null, null, 1));
        $ids = array_map(static fn ($row) => $row->decisionId, $snapshot->rows);

        self::assertEqualsCanonicalizing(array_map(static fn (Decision $decision): int => (int) $decision->id(), $world['decisions']->all()), $ids);
        self::assertContains($world['projector']->id(), $ids);
        self::assertSame($world['projector']->wording(), $this->row($snapshot, (int) $world['projector']->id())->wording);

        $projector = $this->row($snapshot, (int) $world['projector']->id());
        self::assertSame('Board meeting', $projector->meetingTitle);
        self::assertSame('2026-09-01', $projector->meetingDate);
        self::assertSame(MeetingStatus::Held, $projector->meetingStatus);
        self::assertSame('§ 7', $projector->agendaNumber);
        self::assertSame('New projector', $projector->agendaTitle);
        self::assertSame(AgendaPresentation::Item, $projector->agendaState);

        $future = $this->row($snapshot, (int) $world['future']->id());
        self::assertSame('2', $future->agendaNumber);
        self::assertSame('Roof', $future->agendaTitle);

        $level = $this->row($snapshot, (int) $world['level']->id());
        self::assertNull($level->agendaItemId);
        self::assertSame(AgendaPresentation::None, $level->agendaState);
        self::assertNull($level->agendaNumber);

        $missingItem = $this->row($snapshot, (int) $world['missingItem']->id());
        self::assertSame(AgendaPresentation::Unavailable, $missingItem->agendaState);
        self::assertSame('Board meeting', $missingItem->meetingTitle);

        $missingMeeting = $this->row($snapshot, (int) $world['missingMeeting']->id());
        self::assertFalse($missingMeeting->meetingAvailable);
        self::assertNull($missingMeeting->meetingTitle);
        self::assertSame('Orphan wording', $missingMeeting->wording);
    }

    public function test_responsible_states_stay_distinct_and_a_deceased_person_remains(): void
    {
        $world = $this->sample();
        $snapshot = $world['register']->snapshot($this->today(), DecisionRegisterQuery::normalize('all', null, null, 1));

        $assigned = $this->row($snapshot, (int) $world['projector']->id());
        self::assertSame(ResponsiblePresentation::Assigned, $assigned->responsibleState);
        self::assertSame('Anna Andersson', $assigned->responsibleName);

        $unassigned = $this->row($snapshot, (int) $world['plan']->id());
        self::assertSame(ResponsiblePresentation::Unassigned, $unassigned->responsibleState);
        self::assertNull($unassigned->responsiblePersonId);
        self::assertNull($unassigned->responsibleName);

        $missing = $this->row($snapshot, (int) $world['missingPerson']->id());
        self::assertSame(ResponsiblePresentation::Unavailable, $missing->responsibleState);
        self::assertNotNull($missing->responsiblePersonId);
        self::assertNull($missing->responsibleName);

        $deceased = $this->row($snapshot, (int) $world['memorial']->id());
        self::assertSame('Erik Memorial', $deceased->responsibleName);
        self::assertSame(ResponsiblePresentation::Assigned, $deceased->responsibleState);
    }

    public function test_overdue_uses_an_open_deadline_before_today(): void
    {
        $world = $this->sample();
        $snapshot = $world['register']->snapshot($this->today(), DecisionRegisterQuery::normalize('all', null, null, 1));

        self::assertTrue($this->row($snapshot, (int) $world['yesterday']->id())->overdue);
        self::assertFalse($this->row($snapshot, (int) $world['todayDeadline']->id())->overdue);
        self::assertFalse($this->row($snapshot, (int) $world['future']->id())->overdue);
        self::assertFalse($this->row($snapshot, (int) $world['undated']->id())->overdue);
        self::assertFalse($this->row($snapshot, (int) $world['doneOld']->id())->overdue);
        self::assertSame(DecisionFollowUp::Done, $this->row($snapshot, (int) $world['doneOld']->id())->followUp);
    }

    public function test_open_and_done_order_does_not_follow_repository_order(): void
    {
        $world = $this->sample();
        $open = $world['register']->snapshot($this->today(), DecisionRegisterQuery::defaults());
        $done = $world['register']->snapshot($this->today(), DecisionRegisterQuery::normalize('done', null, null, 1));

        self::assertSame($world['openOrder'], array_map(static fn ($row) => $row->decisionId, $open->rows));
        self::assertSame($world['doneOrder'], array_map(static fn ($row) => $row->decisionId, $done->rows));
        $storedOpen = [];

        foreach ($world['decisions']->all() as $decision) {
            if ($decision->followUp() === DecisionFollowUp::Open) {
                $storedOpen[] = (int) $decision->id();
            }
        }

        self::assertNotSame($storedOpen, $world['openOrder']);
    }

    public function test_filters_default_to_open_and_accept_done_all_responsible_and_overdue(): void
    {
        $world = $this->sample();
        $register = $world['register'];
        $today = $this->today();
        $defaults = $register->snapshot($today, DecisionRegisterQuery::defaults());

        self::assertSame(DecisionRegisterQuery::OPEN, $defaults->query->followUp);
        foreach ($defaults->rows as $row) {
            self::assertSame(DecisionFollowUp::Open, $row->followUp);
        }

        $done = $register->snapshot($today, DecisionRegisterQuery::normalize('done', null, null, 1));
        foreach ($done->rows as $row) {
            self::assertSame(DecisionFollowUp::Done, $row->followUp);
        }

        $all = $register->snapshot($today, DecisionRegisterQuery::normalize('all', null, null, 1));
        self::assertSame($defaults->openCount + $defaults->doneCount, $all->matched);
        self::assertSame(DecisionFollowUp::Open, $all->rows[0]->followUp);
        self::assertSame(DecisionFollowUp::Done, $all->rows[array_key_last($all->rows)]->followUp);

        $anna = (int) $world['anna']->id();
        $responsible = $register->snapshot($today, DecisionRegisterQuery::normalize('all', (string) $anna, null, 1));
        self::assertNotEmpty($responsible->rows);
        foreach ($responsible->rows as $row) {
            self::assertSame($anna, $row->responsiblePersonId);
        }

        $unassigned = $register->snapshot($today, DecisionRegisterQuery::normalize('all', 'unassigned', null, 1));
        self::assertNotEmpty($unassigned->rows);
        foreach ($unassigned->rows as $row) {
            self::assertSame(ResponsiblePresentation::Unassigned, $row->responsibleState);
        }

        $overdue = $register->snapshot($today, DecisionRegisterQuery::normalize('open', null, 'overdue', 1));
        self::assertNotEmpty($overdue->rows);
        foreach ($overdue->rows as $row) {
            self::assertTrue($row->overdue);
            self::assertSame(DecisionFollowUp::Open, $row->followUp);
        }

        $doneOverdue = $register->snapshot($today, DecisionRegisterQuery::normalize('done', null, 'overdue', 1));
        self::assertSame(0, $doneOverdue->matched);

        $fallback = DecisionRegisterQuery::normalize('deleted', 'Ada', 'soon', 0);
        self::assertSame(DecisionRegisterQuery::OPEN, $fallback->followUp);
        self::assertSame(DecisionRegisterQuery::ALL, $fallback->responsible);
        self::assertSame(DecisionRegisterQuery::ALL, $fallback->urgency);
        self::assertSame(1, $fallback->page);
        self::assertSame($defaults->openCount, $register->snapshot($today, $fallback)->matched);
    }

    public function test_pagination_is_deterministic_and_out_of_range_pages_clamp(): void
    {
        $decisions = new MemoryDecisionRepository();
        $meetings = new MemoryMeetingRepository();
        $meeting = $meetings->add(new Meeting(null, 1, 'Pages', MeetingMoment::fromLocal('2026-01-01 18:00'), '', MeetingStatus::Held));
        $meetingId = (int) $meeting->id();

        for ($index = 0; $index < 51; $index++) {
            $decisions->add(new Decision(null, $meetingId, null, 'Page decision ' . $index, null, null, DecisionFollowUp::Open));
        }

        $register = $this->register($decisions, $meetings, new MemoryAgendaRepository(), new MemoryPersonRepository(), [Capabilities::VIEW_INTERNAL_MEETINGS]);
        $first = $register->snapshot($this->today(), DecisionRegisterQuery::normalize('open', null, null, 1));
        $second = $register->snapshot($this->today(), DecisionRegisterQuery::normalize('open', null, null, 2));
        $clamped = $register->snapshot($this->today(), DecisionRegisterQuery::normalize('open', null, null, 99));

        self::assertSame(50, count($first->rows));
        self::assertSame(51, $first->rows[0]->decisionId);
        self::assertSame(2, $first->rows[49]->decisionId);
        self::assertSame(1, count($second->rows));
        self::assertSame(1, $second->rows[0]->decisionId);
        self::assertSame(2, $clamped->page);
        self::assertSame(1, $clamped->rows[0]->decisionId);
        self::assertSame($first->openCount, $second->openCount);
    }

    public function test_recording_can_change_follow_up_without_changing_the_decision(): void
    {
        $world = $this->mutationWorld();
        $record = $world['record'];
        $id = $world['id'];
        $before = $world['decisions']->find($id);
        self::assertNotNull($before);

        $record->setFollowUp($id, DecisionFollowUp::Done, null);
        $done = $world['decisions']->find($id);
        self::assertNotNull($done);
        self::assertSame(DecisionFollowUp::Done, $done->followUp());
        self::assertSame($before->wording(), $done->wording());
        self::assertSame($before->responsiblePersonId(), $done->responsiblePersonId());
        self::assertSame($before->deadline()?->iso(), $done->deadline()?->iso());
        self::assertSame($before->meetingId(), $done->meetingId());
        self::assertSame($before->agendaItemId(), $done->agendaItemId());

        $record->setFollowUp($id, DecisionFollowUp::Done, null);
        self::assertSame(DecisionFollowUp::Done, $world['decisions']->find($id)?->followUp());

        $record->setFollowUp($id, DecisionFollowUp::Open, null);
        $reopened = $world['decisions']->find($id);
        self::assertNotNull($reopened);
        self::assertSame(DecisionFollowUp::Open, $reopened->followUp());
        self::assertSame($before->wording(), $reopened->wording());
        self::assertSame($before->responsiblePersonId(), $reopened->responsiblePersonId());
        self::assertSame($before->deadline()?->iso(), $reopened->deadline()?->iso());
    }

    public function test_viewing_meetings_cannot_change_follow_up(): void
    {
        $world = $this->mutationWorld([Capabilities::VIEW_INTERNAL_MEETINGS]);

        try {
            $world['record']->setFollowUp($world['id'], DecisionFollowUp::Done, null);
            self::fail('Follow-up should require record_meeting.');
        } catch (NotAllowed $error) {
            self::assertSame(Capabilities::RECORD_MEETING, $error->getMessage());
        }

        self::assertSame(DecisionFollowUp::Open, $world['decisions']->find($world['id'])?->followUp());
    }

    public function test_invalid_and_unknown_follow_up_changes_are_rejected(): void
    {
        try {
            DecisionRegisterQuery::followUpChange('deleted');
            self::fail('deleted is not a follow-up status.');
        } catch (InvalidArgumentException) {
        }

        $world = $this->mutationWorld();

        try {
            $world['record']->setFollowUp(9999, DecisionFollowUp::Done, null);
            self::fail('An unknown decision should be rejected.');
        } catch (\RuntimeException) {
        }

        self::assertSame(DecisionFollowUp::Open, $world['decisions']->find($world['id'])?->followUp());
    }

    public function test_a_submitted_meeting_id_does_not_authorize_follow_up(): void
    {
        $world = $this->mutationWorld();

        try {
            $world['record']->setFollowUp($world['id'], DecisionFollowUp::Done, 9999);
            self::fail('A foreign meeting id should not move the decision.');
        } catch (MeetingRuleException) {
        }

        self::assertSame(DecisionFollowUp::Open, $world['decisions']->find($world['id'])?->followUp());
        $world['record']->setFollowUp($world['id'], DecisionFollowUp::Done, null);
        self::assertSame(DecisionFollowUp::Done, $world['decisions']->find($world['id'])?->followUp());
        self::assertSame($world['meetingId'], $world['decisions']->find($world['id'])?->meetingId());
    }

    public function test_the_decisions_screen_does_not_edit_or_delete(): void
    {
        $methods = get_class_methods(DecisionRegister::class);
        self::assertNotContains('remove', $methods);
        self::assertNotContains('reviseDecision', $methods);
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/DecisionsPage.php');
        self::assertStringNotContainsString('removeDecision', $source);
        self::assertStringNotContainsString('reviseDecision', $source);
        self::assertStringNotContainsString('::remove(', $source);
        self::assertContains('setFollowUp', get_class_methods(DecisionsPage::class));
    }

    public function test_follow_up_after_finalized_minutes_leaves_the_revision_unchanged(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $types->add(new MeetingType(null, 'board_meeting', 'Board meeting', 10));
        $meetings = new MemoryMeetingRepository();
        $people = new MemoryPersonRepository();
        $agenda = new MemoryAgendaRepository();
        $decisions = new MemoryDecisionRepository();
        $minutes = new MemoryMinutesRepository();
        $authorizer = $this->authorizer([
            Capabilities::MANAGE_MEETINGS,
            Capabilities::RECORD_MEETING,
            Capabilities::VIEW_INTERNAL_MEETINGS,
            Capabilities::FINALIZE_MINUTES,
        ]);
        $transaction = $this->transaction();
        $meetingService = new MeetingService($types, $meetings, new MeetingLifecycle(), $authorizer, $transaction);
        $drafts = new MinutesDrafts(
            $meetings,
            $people,
            new MemoryParticipantRepository(),
            $agenda,
            new MemoryNoteRepository(),
            $decisions,
            new MemoryActionItemRepository(),
            $minutes,
            new MinutesComposer(),
            new MinutesLifecycle(),
            new AgendaOrder(),
            $authorizer,
            $transaction
        );
        $record = new MeetingRecord(
            $meetings,
            $agenda,
            $people,
            new MemoryNoteRepository(),
            $decisions,
            new MemoryActionItemRepository(),
            $authorizer,
            $transaction
        );
        $meetingId = $meetingService->schedule(1, 'Board meeting', MeetingMoment::fromLocal('2026-09-01 18:00'), '');
        $meetingService->markHeld($meetingId);
        $itemId = (int) $agenda->add(new AgendaItem(null, $meetingId, 1, 'Projector', ''))->id();
        $decision = $decisions->add(new Decision(
            null,
            $meetingId,
            $itemId,
            'Buy projector',
            null,
            null,
            DecisionFollowUp::Open
        ));
        $draftId = $drafts->create($meetingId);
        $drafts->submit($draftId);
        $drafts->finalize($draftId);
        $locked = $drafts->current($meetingId);
        self::assertNotNull($locked);
        self::assertSame(RevisionState::Finalized, $locked->state());
        self::assertStringContainsString('Buy projector', $locked->body());
        $body = $locked->body();
        $payload = $locked->payload();
        $number = $locked->number();
        $visibility = $locked->visibility();
        $revisionId = $locked->id();

        $record->setFollowUp((int) $decision->id(), DecisionFollowUp::Done, null);
        $afterDone = $drafts->current($meetingId);
        self::assertNotNull($afterDone);
        self::assertSame(DecisionFollowUp::Done, $decisions->find((int) $decision->id())?->followUp());
        self::assertSame($body, $afterDone->body());
        self::assertSame($payload, $afterDone->payload());
        self::assertSame($number, $afterDone->number());
        self::assertSame($visibility, $afterDone->visibility());
        self::assertSame($revisionId, $afterDone->id());

        $record->setFollowUp((int) $decision->id(), DecisionFollowUp::Open, null);
        $afterOpen = $drafts->current($meetingId);
        self::assertNotNull($afterOpen);
        self::assertSame(DecisionFollowUp::Open, $decisions->find((int) $decision->id())?->followUp());
        self::assertSame($body, $afterOpen->body());
        self::assertSame($payload, $afterOpen->payload());
        self::assertSame($number, $afterOpen->number());
        self::assertSame($visibility, $afterOpen->visibility());
        self::assertSame($revisionId, $afterOpen->id());
    }

    /**
     * @return array{
     *     register: DecisionRegister,
     *     hidden: DecisionRegister,
     *     decisions: MemoryDecisionRepository,
     *     projector: Decision,
     *     plan: Decision,
     *     level: Decision,
     *     missingItem: Decision,
     *     missingMeeting: Decision,
     *     missingPerson: Decision,
     *     memorial: Decision,
     *     yesterday: Decision,
     *     todayDeadline: Decision,
     *     future: Decision,
     *     undated: Decision,
     *     doneOld: Decision,
     *     anna: Person,
     *     openOrder: list<int>,
     *     doneOrder: list<int>
     * }
     */
    private function sample(): array
    {
        $decisions = new MemoryDecisionRepository();
        $meetings = new MemoryMeetingRepository();
        $agenda = new MemoryAgendaRepository();
        $people = new MemoryPersonRepository();
        $anna = $people->add(new Person(null, 'Anna', 'Andersson', 'anna@example.test', PersonStatus::Known, null));
        $erik = $people->add(new Person(null, 'Erik', 'Memorial', 'erik@example.test', PersonStatus::Deceased, null));
        $board = $meetings->add(new Meeting(null, 1, 'Board meeting', MeetingMoment::fromLocal('2026-09-01 18:00'), '', MeetingStatus::Held));
        $older = $meetings->add(new Meeting(null, 1, 'Older meeting', MeetingMoment::fromLocal('2026-01-01 18:00'), '', MeetingStatus::Held));
        $newer = $meetings->add(new Meeting(null, 1, 'Newer meeting', MeetingMoment::fromLocal('2026-05-01 18:00'), '', MeetingStatus::Held));
        $progress = $meetings->add(new Meeting(null, 1, 'Working meeting', MeetingMoment::fromLocal('2026-09-20 18:00'), '', MeetingStatus::InProgress));
        $item = $agenda->add(new AgendaItem(null, (int) $board->id(), 3, 'New projector', '§ 7'));
        $positionItem = $agenda->add(new AgendaItem(null, (int) $progress->id(), 2, 'Roof', ''));

        $add = static function (
            int $meetingId,
            ?int $agendaItemId,
            string $wording,
            ?int $personId,
            ?string $deadline,
            DecisionFollowUp $followUp,
        ) use ($decisions): Decision {
            return $decisions->add(new Decision(
                null,
                $meetingId,
                $agendaItemId,
                $wording,
                $personId,
                $deadline === null ? null : AssociationDate::fromIso($deadline),
                $followUp
            ));
        };

        $undatedOld = $add((int) $older->id(), null, 'Undated older', null, null, DecisionFollowUp::Open);
        $overdueLater = $add((int) $board->id(), (int) $item->id(), 'Overdue later deadline', (int) $anna->id(), '2026-08-01', DecisionFollowUp::Open);
        $future = $add((int) $progress->id(), (int) $positionItem->id(), 'Future deadline', (int) $anna->id(), '2026-12-01', DecisionFollowUp::Open);
        $overdueOlderMeeting = $add((int) $older->id(), null, 'Overdue older meeting', null, '2026-07-01', DecisionFollowUp::Open);
        $todayDeadline = $add((int) $board->id(), null, 'Due today', null, self::TODAY, DecisionFollowUp::Open);
        $undatedNewer = $add((int) $newer->id(), null, 'Undated newer', null, null, DecisionFollowUp::Open);
        $overdueNewerMeeting = $add((int) $newer->id(), null, 'Overdue newer meeting', null, '2026-07-01', DecisionFollowUp::Open);
        $doneRecent = $add((int) $newer->id(), null, 'Done recent', (int) $erik->id(), '2020-01-01', DecisionFollowUp::Done);
        $doneOld = $add((int) $older->id(), null, 'Done old', null, '2020-01-02', DecisionFollowUp::Done);
        $doneSameDate = $add((int) $newer->id(), null, 'Done same date', null, null, DecisionFollowUp::Done);
        $level = $add((int) $board->id(), null, 'Meeting level', null, null, DecisionFollowUp::Open);
        $missingItem = $add((int) $board->id(), 50, 'Missing agenda', null, null, DecisionFollowUp::Open);
        $missingMeeting = $add(80, null, 'Orphan wording', null, null, DecisionFollowUp::Open);
        $missingPerson = $add((int) $board->id(), null, 'Missing person', 4242, null, DecisionFollowUp::Open);

        return [
            'register' => $this->register($decisions, $meetings, $agenda, $people, [Capabilities::VIEW_INTERNAL_MEETINGS]),
            'hidden' => $this->register($decisions, $meetings, $agenda, $people, [Capabilities::MANAGE_ASSOCIATION, Capabilities::ACCESS_ASSOCIATION]),
            'decisions' => $decisions,
            'projector' => $overdueLater,
            'plan' => $undatedOld,
            'level' => $level,
            'missingItem' => $missingItem,
            'missingMeeting' => $missingMeeting,
            'missingPerson' => $missingPerson,
            'memorial' => $doneRecent,
            'yesterday' => $overdueLater,
            'todayDeadline' => $todayDeadline,
            'future' => $future,
            'undated' => $undatedNewer,
            'doneOld' => $doneOld,
            'anna' => $anna,
            'openOrder' => [
                (int) $overdueNewerMeeting->id(),
                (int) $overdueOlderMeeting->id(),
                (int) $overdueLater->id(),
                (int) $todayDeadline->id(),
                (int) $future->id(),
                (int) $missingPerson->id(),
                (int) $missingItem->id(),
                (int) $level->id(),
                (int) $undatedNewer->id(),
                (int) $undatedOld->id(),
                (int) $missingMeeting->id(),
            ],
            'doneOrder' => [
                (int) $doneSameDate->id(),
                (int) $doneRecent->id(),
                (int) $doneOld->id(),
            ],
        ];
    }

    /**
     * @param list<string> $capabilities
     * @return array{record: MeetingRecord, decisions: MemoryDecisionRepository, id: int, meetingId: int}
     */
    private function mutationWorld(?array $capabilities = null): array
    {
        $capabilities ??= [Capabilities::RECORD_MEETING, Capabilities::VIEW_INTERNAL_MEETINGS];
        $decisions = new MemoryDecisionRepository();
        $meetings = new MemoryMeetingRepository();
        $meeting = $meetings->add(new Meeting(null, 1, 'Board meeting', MeetingMoment::fromLocal('2026-09-01 18:00'), '', MeetingStatus::Held));
        $decision = $decisions->add(new Decision(
            null,
            (int) $meeting->id(),
            null,
            'Buy a new projector',
            null,
            AssociationDate::fromIso('2026-10-10'),
            DecisionFollowUp::Open
        ));
        $record = new MeetingRecord(
            $meetings,
            new MemoryAgendaRepository(),
            new MemoryPersonRepository(),
            new MemoryNoteRepository(),
            $decisions,
            new MemoryActionItemRepository(),
            $this->authorizer($capabilities),
            $this->transaction()
        );

        return [
            'record' => $record,
            'decisions' => $decisions,
            'id' => (int) $decision->id(),
            'meetingId' => (int) $meeting->id(),
        ];
    }

    /**
     * @param list<string> $capabilities
     */
    private function register(
        MemoryDecisionRepository $decisions,
        MemoryMeetingRepository $meetings,
        MemoryAgendaRepository $agenda,
        MemoryPersonRepository $people,
        array $capabilities,
    ): DecisionRegister {
        return new DecisionRegister($decisions, $meetings, $agenda, $people, $this->authorizer($capabilities));
    }

    /**
     * @param list<string> $capabilities
     */
    private function authorizer(array $capabilities): Authorizer
    {
        return new class ($capabilities) implements Authorizer {
            /** @param list<string> $capabilities */
            public function __construct(private array $capabilities)
            {
            }

            public function allows(string $capability): bool
            {
                return in_array($capability, $this->capabilities, true);
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

    private function today(): AssociationDate
    {
        return AssociationDate::fromIso(self::TODAY);
    }

    private function row(DecisionRegisterSnapshot $snapshot, int $id): \Foreningssystem\Application\Decision\DecisionRegisterRow
    {
        foreach ($snapshot->rows as $row) {
            if ($row->decisionId === $id) {
                return $row;
            }
        }

        self::fail('Decision ' . $id . ' was missing from the register.');
    }
}
