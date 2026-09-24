<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\ActionItemRepository;
use Foreningssystem\Domain\Meeting\AgendaOrder;
use Foreningssystem\Domain\Meeting\AgendaRepository;
use Foreningssystem\Domain\Meeting\DecisionRepository;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingNoteRepository;
use Foreningssystem\Domain\Meeting\MeetingRepository;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MinutesRepository;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\ParticipantRepository;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;

final class MinutesDrafts
{
    public function __construct(
        private readonly MeetingRepository $meetings,
        private readonly PersonRepository $people,
        private readonly ParticipantRepository $participants,
        private readonly AgendaRepository $agenda,
        private readonly MeetingNoteRepository $notes,
        private readonly DecisionRepository $decisions,
        private readonly ActionItemRepository $actionItems,
        private readonly MinutesRepository $minutes,
        private readonly MinutesComposer $composer,
        private readonly AgendaOrder $order,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
    ) {
    }

    public function create(int $meetingId): int
    {
        $this->requireRecord();
        $meeting = $this->requireHeld($meetingId);
        $this->requireNoDraft($meetingId);
        $composition = $this->composition($meeting);

        $saved = $this->transaction->run(function () use ($meeting, $composition): MinutesRevision {
            $this->requireNoDraft((int) $meeting->id());
            $minutesId = $this->minutes->findDocumentId((int) $meeting->id()) ?? $this->minutes->addDocument((int) $meeting->id());

            return $this->minutes->addRevision(new MinutesRevision(
                null,
                $minutesId,
                (int) $meeting->id(),
                $this->minutes->nextNumber((int) $meeting->id()),
                RevisionState::Draft,
                $composition->body(),
                $composition->payload(),
                false
            ));
        });

        $id = $saved->id();

        if ($id === null) {
            throw new \RuntimeException('The minutes draft was not saved.');
        }

        return $id;
    }

    public function current(int $meetingId): ?MinutesRevision
    {
        $this->require(Capabilities::VIEW_INTERNAL_MEETINGS);
        $this->requireMeeting($meetingId);

        return $this->minutes->draftForMeeting($meetingId);
    }

    public function isStale(int $meetingId): bool
    {
        $draft = $this->current($meetingId);

        if (! $draft instanceof MinutesRevision) {
            return false;
        }

        return $this->composition($this->requireMeeting($meetingId))->payload() !== $draft->payload();
    }

    public function replaceBody(int $revisionId, string $body): void
    {
        $this->requireRecord();
        $draft = $this->requireDraft($revisionId);

        $this->transaction->run(function () use ($draft, $body): void {
            $this->minutes->saveRevision($draft->withBody($body));
        });
    }

    public function regenerate(int $revisionId, bool $confirmed): void
    {
        $this->requireRecord();
        $draft = $this->requireDraft($revisionId);

        if ($draft->handEdited() && ! $confirmed) {
            throw new MeetingRuleException('Confirm before replacing a hand-edited draft.');
        }

        $composition = $this->composition($this->requireMeeting($draft->meetingId()));

        $this->transaction->run(function () use ($draft, $composition): void {
            $this->minutes->saveRevision($draft->regenerated($composition->body(), $composition->payload()));
        });
    }

    private function composition(Meeting $meeting): MinutesComposition
    {
        $meetingId = (int) $meeting->id();

        return $this->composer->compose(
            $meeting,
            $this->attendance($meetingId),
            $this->order->sorted($this->agenda->forMeeting($meetingId)),
            $this->notes->forMeeting($meetingId),
            $this->decisionRows($meetingId),
            $this->actionRows($meetingId)
        );
    }

    /**
     * @return list<AttendanceRow>
     */
    private function attendance(int $meetingId): array
    {
        $rows = [];

        foreach ($this->participants->forMeeting($meetingId) as $participant) {
            $person = $this->people->find($participant->personId());

            if (! $person instanceof Person) {
                continue;
            }

            $rows[] = new AttendanceRow($participant, $person->firstName() . ' ' . $person->lastName());
        }

        usort(
            $rows,
            static fn (AttendanceRow $left, AttendanceRow $right): int => strcasecmp($left->personName(), $right->personName())
        );

        return $rows;
    }

    /**
     * @return list<DecisionRow>
     */
    private function decisionRows(int $meetingId): array
    {
        $rows = [];

        foreach ($this->decisions->forMeeting($meetingId) as $decision) {
            $name = null;
            $personId = $decision->responsiblePersonId();

            if ($personId !== null) {
                $person = $this->people->find($personId);
                $name = $person instanceof Person ? $person->firstName() . ' ' . $person->lastName() : null;
            }

            $rows[] = new DecisionRow($decision, $name);
        }

        return $rows;
    }

    /**
     * @return list<ActionItemRow>
     */
    private function actionRows(int $meetingId): array
    {
        $rows = [];

        foreach ($this->actionItems->forMeeting($meetingId) as $item) {
            $name = null;
            $personId = $item->assigneePersonId();

            if ($personId !== null) {
                $person = $this->people->find($personId);
                $name = $person instanceof Person ? $person->firstName() . ' ' . $person->lastName() : null;
            }

            $rows[] = new ActionItemRow($item, $name);
        }

        return $rows;
    }

    private function requireHeld(int $meetingId): Meeting
    {
        $meeting = $this->requireMeeting($meetingId);

        if ($meeting->status() !== MeetingStatus::Held) {
            throw new MeetingRuleException('Minutes are drafted after the meeting is held.');
        }

        return $meeting;
    }

    private function requireNoDraft(int $meetingId): void
    {
        if ($this->minutes->draftForMeeting($meetingId) instanceof MinutesRevision) {
            throw new MeetingRuleException('This meeting already has a draft.');
        }
    }

    private function requireDraft(int $revisionId): MinutesRevision
    {
        $revision = $this->minutes->findRevision($revisionId);

        if (! $revision instanceof MinutesRevision || $revision->state() !== RevisionState::Draft) {
            throw new \RuntimeException('Minutes draft was not found.');
        }

        return $revision;
    }

    private function requireMeeting(int $meetingId): Meeting
    {
        $meeting = $this->meetings->find($meetingId);

        if (! $meeting instanceof Meeting || $meeting->id() === null) {
            throw new \RuntimeException('Meeting was not found.');
        }

        return $meeting;
    }

    private function requireRecord(): void
    {
        $this->require(Capabilities::RECORD_MEETING);
    }

    private function require(string $capability): void
    {
        if (! $this->authorizer->allows($capability)) {
            throw new NotAllowed($capability);
        }
    }
}
