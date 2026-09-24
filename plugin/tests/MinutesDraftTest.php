<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Meeting\MinutesComposer;
use Foreningssystem\Application\Meeting\MinutesDrafts;
use Foreningssystem\Domain\Meeting\MinutesLifecycle;
use Foreningssystem\Application\Meeting\MeetingService;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\ActionItem;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\AgendaItem;
use Foreningssystem\Domain\Meeting\AgendaOrder;
use Foreningssystem\Domain\Meeting\Decision;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingLifecycle;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingNote;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MeetingType;
use Foreningssystem\Domain\Meeting\MinutesRepository;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\Participant;
use Foreningssystem\Domain\Meeting\PublicationVisibility;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use Foreningssystem\Infrastructure\Persistence\MinutesSchemaMigration;
use PHPUnit\Framework\TestCase;

final class MinutesDraftTest extends TestCase
{
    public function test_a_revision_cannot_be_changed_through_another_meeting(): void
    {
        [$meetings, $drafts] = $this->world([
            Capabilities::RECORD_MEETING,
            Capabilities::MANAGE_MEETINGS,
            Capabilities::VIEW_INTERNAL_MEETINGS,
            Capabilities::FINALIZE_MINUTES,
        ]);
        $first = $meetings->schedule(1, 'Första', MeetingMoment::fromLocal('2026-10-02 18:00'), '');
        $second = $meetings->schedule(1, 'Andra', MeetingMoment::fromLocal('2026-11-02 18:00'), '');
        $meetings->markHeld($first);
        $meetings->markHeld($second);
        $firstDraft = $drafts->create($first);
        $secondDraft = $drafts->create($second);
        $body = (string) $drafts->current($first)?->body();

        foreach ([
            static fn () => $drafts->replaceBody($firstDraft, 'Tyst ändring.', $second),
            static fn () => $drafts->regenerate($firstDraft, true, $second),
            static fn () => $drafts->submit($firstDraft, $second),
            static fn () => $drafts->sendBack($firstDraft, $second),
            static fn () => $drafts->finalize($firstDraft, $second),
        ] as $change) {
            try {
                $change();
                self::fail('A revision should stay on its own meeting.');
            } catch (MeetingRuleException $error) {
                self::assertSame('The record does not belong to this meeting.', $error->getMessage());
            }
        }

        $kept = $drafts->current($first);
        self::assertNotNull($kept);
        self::assertSame($body, $kept->body());
        self::assertSame(RevisionState::Draft, $kept->state());
        self::assertSame(RevisionState::Draft, $drafts->current($second)?->state());
        self::assertNotSame($firstDraft, $secondDraft);
    }

    public function test_a_draft_keeps_the_copied_text_when_live_rows_change(): void
    {
        [$meetings, $drafts, $people, $participants, $agenda, $notes, $decisions, $actions] = $this->world([
            Capabilities::RECORD_MEETING,
            Capabilities::MANAGE_MEETINGS,
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ]);
        $personId = (int) $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null))->id();
        $meetingId = $meetings->schedule(1, 'Styrelsemöte', MeetingMoment::fromLocal('2024-05-02 18:00'), 'Lokalen');
        $participants->add(new Participant(null, $meetingId, $personId, Presence::Present, MeetingDuty::Chair));
        $itemId = (int) $agenda->add(new AgendaItem(null, $meetingId, 1, 'Inköp', '§2'))->id();
        $notes->add(new MeetingNote(null, $meetingId, $itemId, 'Tre offerter granskades.', true));
        $notes->add(new MeetingNote(null, $meetingId, $itemId, 'Intern skiss.', false));
        $decision = $decisions->add(new Decision(
            null,
            $meetingId,
            $itemId,
            'Föreningen köper modell X.',
            null,
            null,
            DecisionFollowUp::Open
        ));
        $action = $actions->add(new ActionItem(
            null,
            $meetingId,
            $itemId,
            'Ada kontaktar kommunen om hyresavtalet.',
            $personId,
            AssociationDate::fromIso('2026-11-15'),
            ActionStatus::Open
        ));

        try {
            $drafts->create($meetingId);
            self::fail('A planned meeting should not get a minutes draft.');
        } catch (MeetingRuleException) {
        }

        $meetings->markHeld($meetingId);
        $draftId = $drafts->create($meetingId);
        $draft = $drafts->current($meetingId);
        self::assertNotNull($draft);
        self::assertSame($draftId, $draft->id());
        self::assertSame(RevisionState::Draft, $draft->state());
        self::assertSame(1, $draft->number());
        self::assertFalse($draft->handEdited());
        self::assertStringContainsString('Styrelsemöte', $draft->body());
        self::assertStringContainsString('2024-05-02 18:00', $draft->body());
        self::assertStringContainsString('Lokalen', $draft->body());
        self::assertStringContainsString('Närvarande', $draft->body());
        self::assertStringContainsString('Ada Lovelace', $draft->body());
        self::assertStringContainsString('§2. Inköp', $draft->body());
        self::assertStringContainsString('Anteckning: Tre offerter granskades.', $draft->body());
        self::assertStringContainsString('Beslut: Föreningen köper modell X.', $draft->body());
        self::assertStringContainsString('Uppgift: Ada kontaktar kommunen om hyresavtalet. (Ada Lovelace, 2026-11-15, öppen)', $draft->body());
        self::assertStringContainsString('Mötesordförande: Ada Lovelace', $draft->body());
        self::assertStringNotContainsString('Intern skiss.', $draft->body());
        self::assertStringNotContainsString('@', $draft->body());
        self::assertStringNotContainsString('@', $draft->payload());
        self::assertFalse($drafts->isStale($meetingId));

        $decisions->save($decision->revised('Föreningen köper modell Y.', null, null));
        $actions->save($action->withStatus(ActionStatus::Done));
        $unchanged = $drafts->current($meetingId);
        self::assertNotNull($unchanged);
        self::assertSame($draft->body(), $unchanged->body());
        self::assertSame($draft->payload(), $unchanged->payload());
        self::assertTrue($drafts->isStale($meetingId));

        $drafts->replaceBody($draftId, 'Egen text i utkastet.');
        $edited = $drafts->current($meetingId);
        self::assertNotNull($edited);
        self::assertTrue($edited->handEdited());
        self::assertSame('Egen text i utkastet.', $edited->body());
        self::assertSame($draft->payload(), $edited->payload());

        try {
            $drafts->regenerate($draftId, false);
            self::fail('A hand-edited draft should stay until replacement is confirmed.');
        } catch (MeetingRuleException) {
        }

        self::assertSame('Egen text i utkastet.', $drafts->current($meetingId)?->body());
        $drafts->regenerate($draftId, true);
        $fresh = $drafts->current($meetingId);
        self::assertNotNull($fresh);
        self::assertFalse($fresh->handEdited());
        self::assertStringContainsString('Beslut: Föreningen köper modell Y.', $fresh->body());
        self::assertStringContainsString('klar', $fresh->body());
        self::assertStringNotContainsString('Intern skiss.', $fresh->body());
        self::assertStringNotContainsString('modell X', $fresh->body());
        self::assertFalse($drafts->isStale($meetingId));

        try {
            $drafts->create($meetingId);
            self::fail('A meeting should keep a single open draft.');
        } catch (MeetingRuleException) {
        }

        self::assertSame(MeetingStatus::Held, $this->meetingStatus($meetings, $meetingId));
    }

    public function test_finalizing_locks_the_text_and_a_correction_is_a_new_revision(): void
    {
        [$meetings, $drafts, $people, $participants, $agenda, $notes, $decisions, $actions] = $this->world([
            Capabilities::RECORD_MEETING,
            Capabilities::MANAGE_MEETINGS,
            Capabilities::VIEW_INTERNAL_MEETINGS,
            Capabilities::FINALIZE_MINUTES,
        ]);
        $personId = (int) $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null))->id();
        $meetingId = $meetings->schedule(1, 'Styrelsemöte', MeetingMoment::fromLocal('2024-05-02 18:00'), 'Lokalen');
        $participants->add(new Participant(null, $meetingId, $personId, Presence::Present, MeetingDuty::Chair));
        $itemId = (int) $agenda->add(new AgendaItem(null, $meetingId, 1, 'Inköp', ''))->id();
        $notes->add(new MeetingNote(null, $meetingId, $itemId, 'Tre offerter granskades.', true));
        $decision = $decisions->add(new Decision(null, $meetingId, $itemId, 'Föreningen köper modell X.', null, null, DecisionFollowUp::Open));
        $actions->add(new ActionItem(null, $meetingId, $itemId, 'Ring kommunen.', null, null, ActionStatus::Open));
        $meetings->markHeld($meetingId);
        $draftId = $drafts->create($meetingId);

        try {
            $drafts->finalize($draftId);
            self::fail('A draft should be adjusted before it is finalized.');
        } catch (MeetingRuleException) {
        }

        $drafts->submit($draftId);
        self::assertSame(RevisionState::UnderAdjustment, $drafts->current($meetingId)?->state());
        $drafts->sendBack($draftId);
        self::assertSame(RevisionState::Draft, $drafts->current($meetingId)?->state());
        $drafts->submit($draftId);
        $drafts->finalize($draftId);
        $locked = $drafts->current($meetingId);
        self::assertNotNull($locked);
        self::assertSame(RevisionState::Finalized, $locked->state());
        self::assertNull($locked->supersededBy());
        $lockedBody = $locked->body();

        $decisions->save($decision->revised('Föreningen köper modell Y.', null, null));
        self::assertSame($lockedBody, $drafts->current($meetingId)?->body());
        self::assertFalse($drafts->isStale($meetingId));

        try {
            $drafts->replaceBody($draftId, 'Tyst ändring.');
            self::fail('A finalized revision should not accept a new body.');
        } catch (MeetingRuleException) {
        }

        try {
            $drafts->regenerate($draftId, true);
            self::fail('A finalized revision should not be regenerated.');
        } catch (MeetingRuleException) {
        }

        self::assertSame($lockedBody, $drafts->revision($draftId)->body());
        $correctionId = $drafts->openCorrection($meetingId);
        $correction = $drafts->current($meetingId);
        self::assertNotNull($correction);
        self::assertSame($correctionId, $correction->id());
        self::assertSame(2, $correction->number());
        self::assertSame(RevisionState::Draft, $correction->state());
        self::assertSame($draftId, $correction->correctsRevisionId());
        self::assertSame($lockedBody, $correction->body());
        self::assertStringContainsString('Föreningen köper modell X.', $correction->body());
        self::assertNull($drafts->revision($draftId)->supersededBy());

        $drafts->submit($correctionId);
        $drafts->finalize($correctionId);
        $previous = $drafts->revision($draftId);
        $latest = $drafts->current($meetingId);
        self::assertSame($lockedBody, $previous->body());
        self::assertSame($correctionId, $previous->supersededBy());
        self::assertSame(RevisionState::Finalized, $previous->state());
        self::assertNotNull($latest);
        self::assertSame($correctionId, $latest->id());
        self::assertSame(RevisionState::Finalized, $latest->state());
        self::assertNull($latest->supersededBy());
        self::assertSame($lockedBody, $latest->body());
    }

    public function test_recording_a_meeting_does_not_allow_finalizing_minutes(): void
    {
        [$meetings, $drafts] = $this->world([
            Capabilities::RECORD_MEETING,
            Capabilities::MANAGE_MEETINGS,
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ]);
        $meetingId = $meetings->schedule(1, 'Styrelsemöte', MeetingMoment::fromLocal('2024-05-02 18:00'), '');
        $meetings->markHeld($meetingId);
        $draftId = $drafts->create($meetingId);
        $drafts->submit($draftId);

        $this->expectException(NotAllowed::class);
        $drafts->finalize($draftId);
    }

    public function test_planning_a_meeting_does_not_allow_a_minutes_draft(): void
    {
        [, $drafts] = $this->world([Capabilities::MANAGE_MEETINGS, Capabilities::VIEW_INTERNAL_MEETINGS]);

        $this->expectException(NotAllowed::class);
        $drafts->create(1);
    }

    public function test_schema_migration_creates_minutes_tables(): void
    {
        $migration = new MinutesSchemaMigration('wp_', '');
        $sql = $migration->statements();

        self::assertSame(8, $migration->version());
        self::assertStringContainsString('CREATE TABLE wp_assoc_minutes ', $sql);
        self::assertStringContainsString('CREATE TABLE wp_assoc_minutes_revision', $sql);
        self::assertStringContainsString('hand_edited', $sql);
        self::assertStringContainsString('payload', $sql);
        self::assertStringNotContainsString('wp_users', $sql);
    }

    /**
     * @param list<string> $capabilities
     * @return array{0: MeetingService, 1: MinutesDrafts, 2: MemoryPersonRepository, 3: MemoryParticipantRepository, 4: MemoryAgendaRepository, 5: MemoryNoteRepository, 6: MemoryDecisionRepository, 7: MemoryActionItemRepository}
     */
    private function world(array $capabilities): array
    {
        $types = new MemoryMeetingTypeRepository();
        $types->add(new MeetingType(null, 'board_meeting', 'Styrelsemöte', 10));
        $meetingRepository = new MemoryMeetingRepository();
        $people = new MemoryPersonRepository();
        $participants = new MemoryParticipantRepository();
        $agenda = new MemoryAgendaRepository();
        $notes = new MemoryNoteRepository();
        $decisions = new MemoryDecisionRepository();
        $actions = new MemoryActionItemRepository();
        $authorizer = new class ($capabilities) implements Authorizer {
            /** @param list<string> $capabilities */
            public function __construct(private array $capabilities)
            {
            }

            public function allows(string $capability): bool
            {
                return in_array($capability, $this->capabilities, true);
            }
        };
        $transaction = new class implements Transaction {
            public function run(callable $callback): mixed
            {
                return $callback();
            }
        };

        return [
            new MeetingService($types, $meetingRepository, new MeetingLifecycle(), $authorizer, $transaction),
            new MinutesDrafts(
                $meetingRepository,
                $people,
                $participants,
                $agenda,
                $notes,
                $decisions,
                $actions,
                new MemoryMinutesRepository(),
                new MinutesComposer(),
                new MinutesLifecycle(),
                new AgendaOrder(),
                $authorizer,
                $transaction
            ),
            $people,
            $participants,
            $agenda,
            $notes,
            $decisions,
            $actions,
        ];
    }

    private function meetingStatus(MeetingService $meetings, int $meetingId): MeetingStatus
    {
        foreach ($meetings->listMeetings() as $meeting) {
            if ($meeting->id() === $meetingId) {
                return $meeting->status();
            }
        }

        self::fail('Meeting was not found.');
    }
}

final class MemoryMinutesRepository implements MinutesRepository
{
    /** @var array<int, int> */
    private array $documents = [];

    /** @var array<int, MinutesRevision> */
    private array $revisions = [];

    private int $nextDocumentId = 1;

    private int $nextRevisionId = 1;

    public function findDocumentId(int $meetingId): ?int
    {
        foreach ($this->documents as $id => $storedMeetingId) {
            if ($storedMeetingId === $meetingId) {
                return $id;
            }
        }

        return null;
    }

    public function addDocument(int $meetingId): int
    {
        $id = $this->nextDocumentId;
        $this->documents[$id] = $meetingId;
        $this->nextDocumentId++;

        return $id;
    }

    public function nextNumber(int $meetingId): int
    {
        $number = 0;

        foreach ($this->revisions as $revision) {
            if ($revision->meetingId() === $meetingId) {
                $number = max($number, $revision->number());
            }
        }

        return $number + 1;
    }

    public function addRevision(MinutesRevision $revision): MinutesRevision
    {
        $saved = $revision->withId($this->nextRevisionId);
        $this->revisions[$this->nextRevisionId] = $saved;
        $this->nextRevisionId++;

        return $saved;
    }

    public function saveRevision(MinutesRevision $revision): void
    {
        $id = $revision->id();

        if ($id === null || ! isset($this->revisions[$id])) {
            throw new \RuntimeException('Minutes draft was not found.');
        }

        $this->revisions[$id] = $revision;
    }

    public function findRevision(int $id): ?MinutesRevision
    {
        return $this->revisions[$id] ?? null;
    }

    public function openForMeeting(int $meetingId): ?MinutesRevision
    {
        $found = null;

        foreach ($this->revisions as $revision) {
            if ($revision->meetingId() !== $meetingId) {
                continue;
            }

            if ($revision->state() !== RevisionState::Draft && $revision->state() !== RevisionState::UnderAdjustment) {
                continue;
            }

            if ($found === null || $revision->number() > $found->number()) {
                $found = $revision;
            }
        }

        return $found;
    }

    public function latestForMeeting(int $meetingId): ?MinutesRevision
    {
        $found = null;

        foreach ($this->revisions as $revision) {
            if ($revision->meetingId() === $meetingId && ($found === null || $revision->number() > $found->number())) {
                $found = $revision;
            }
        }

        return $found;
    }

    public function forMeeting(int $meetingId): array
    {
        $rows = [];

        foreach ($this->revisions as $revision) {
            if ($revision->meetingId() === $meetingId) {
                $rows[] = $revision;
            }
        }

        return $rows;
    }

    public function publicRevisions(): array
    {
        $rows = [];

        foreach ($this->revisions as $revision) {
            if ($revision->visibility() === PublicationVisibility::Public && $revision->supersededBy() === null) {
                $rows[] = $revision;
            }
        }

        return $rows;
    }
}
