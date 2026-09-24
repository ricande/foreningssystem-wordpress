<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\ActionItem;
use Foreningssystem\Domain\Meeting\ActionItemRepository;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\AgendaRepository;
use Foreningssystem\Domain\Meeting\Decision;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\DecisionRepository;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingNote;
use Foreningssystem\Domain\Meeting\MeetingNoteRepository;
use Foreningssystem\Domain\Meeting\MeetingRepository;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;

final class MeetingRecord
{
    public function __construct(
        private readonly MeetingRepository $meetings,
        private readonly AgendaRepository $agenda,
        private readonly PersonRepository $people,
        private readonly MeetingNoteRepository $notes,
        private readonly DecisionRepository $decisions,
        private readonly ActionItemRepository $actionItems,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
    ) {
    }

    public function addNote(int $meetingId, ?int $agendaItemId, string $body, bool $includeInMinutes): int
    {
        $this->requireRecord();
        $this->requireMeeting($meetingId);
        $this->requireAgendaItem($meetingId, $agendaItemId);

        $saved = $this->transaction->run(function () use ($meetingId, $agendaItemId, $body, $includeInMinutes): MeetingNote {
            return $this->notes->add(new MeetingNote(null, $meetingId, $agendaItemId, $body, $includeInMinutes));
        });

        return $this->savedId($saved->id(), 'The note was not saved.');
    }

    public function reviseNote(int $noteId, string $body, bool $includeInMinutes): void
    {
        $this->requireRecord();
        $note = $this->requireNote($noteId);

        $this->transaction->run(function () use ($note, $body, $includeInMinutes): void {
            $this->notes->save($note->revised($body, $includeInMinutes));
        });
    }

    public function removeNote(int $noteId): void
    {
        $this->requireRecord();
        $this->requireNote($noteId);

        $this->transaction->run(function () use ($noteId): void {
            $this->notes->remove($noteId);
        });
    }

    public function addDecision(
        int $meetingId,
        ?int $agendaItemId,
        string $wording,
        ?int $responsiblePersonId,
        ?AssociationDate $deadline,
    ): int {
        $this->requireRecord();
        $this->requireMeeting($meetingId);
        $this->requireAgendaItem($meetingId, $agendaItemId);
        $this->requirePerson($responsiblePersonId);

        $saved = $this->transaction->run(function () use ($meetingId, $agendaItemId, $wording, $responsiblePersonId, $deadline): Decision {
            return $this->decisions->add(new Decision(
                null,
                $meetingId,
                $agendaItemId,
                $wording,
                $responsiblePersonId,
                $deadline,
                DecisionFollowUp::Open
            ));
        });

        return $this->savedId($saved->id(), 'The decision was not saved.');
    }

    public function reviseDecision(int $decisionId, string $wording, ?int $responsiblePersonId, ?AssociationDate $deadline): void
    {
        $this->requireRecord();
        $decision = $this->requireDecision($decisionId);
        $this->requirePerson($responsiblePersonId);

        $this->transaction->run(function () use ($decision, $wording, $responsiblePersonId, $deadline): void {
            $this->decisions->save($decision->revised($wording, $responsiblePersonId, $deadline));
        });
    }

    public function setFollowUp(int $decisionId, DecisionFollowUp $followUp): void
    {
        $this->requireRecord();
        $decision = $this->requireDecision($decisionId);

        $this->transaction->run(function () use ($decision, $followUp): void {
            $this->decisions->save($decision->withFollowUp($followUp));
        });
    }

    public function removeDecision(int $decisionId): void
    {
        $this->requireRecord();
        $this->requireDecision($decisionId);

        $this->transaction->run(function () use ($decisionId): void {
            $this->decisions->remove($decisionId);
        });
    }

    /**
     * @return list<MeetingNote>
     */
    public function notes(int $meetingId): array
    {
        $this->require(Capabilities::VIEW_INTERNAL_MEETINGS);
        $this->requireMeeting($meetingId);

        return $this->notes->forMeeting($meetingId);
    }

    /**
     * @return list<DecisionRow>
     */
    public function decisions(int $meetingId): array
    {
        $this->require(Capabilities::VIEW_INTERNAL_MEETINGS);
        $this->requireMeeting($meetingId);
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

    public function openCount(): int
    {
        $this->require(Capabilities::VIEW_INTERNAL_MEETINGS);
        $count = 0;

        foreach ($this->decisions->all() as $decision) {
            if ($decision->followUp() === DecisionFollowUp::Open) {
                $count++;
            }
        }

        return $count;
    }

    public function addActionItem(
        int $meetingId,
        ?int $agendaItemId,
        string $task,
        ?int $assigneePersonId,
        ?AssociationDate $dueOn,
    ): int {
        $this->requireRecord();
        $this->requireMeeting($meetingId);
        $this->requireAgendaItem($meetingId, $agendaItemId);
        $this->requirePerson($assigneePersonId);

        $saved = $this->transaction->run(function () use ($meetingId, $agendaItemId, $task, $assigneePersonId, $dueOn): ActionItem {
            return $this->actionItems->add(new ActionItem(
                null,
                $meetingId,
                $agendaItemId,
                $task,
                $assigneePersonId,
                $dueOn,
                ActionStatus::Open
            ));
        });

        return $this->savedId($saved->id(), 'The action item was not saved.');
    }

    public function setActionStatus(int $actionItemId, ActionStatus $status): void
    {
        $this->requireRecord();
        $item = $this->requireActionItem($actionItemId);

        $this->transaction->run(function () use ($item, $status): void {
            $this->actionItems->save($item->withStatus($status));
        });
    }

    public function removeActionItem(int $actionItemId): void
    {
        $this->requireRecord();
        $this->requireActionItem($actionItemId);

        $this->transaction->run(function () use ($actionItemId): void {
            $this->actionItems->remove($actionItemId);
        });
    }

    /**
     * @return list<ActionItemRow>
     */
    public function actionItems(int $meetingId): array
    {
        $this->require(Capabilities::VIEW_INTERNAL_MEETINGS);
        $this->requireMeeting($meetingId);
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

    public function openActionCount(): int
    {
        $this->require(Capabilities::VIEW_INTERNAL_MEETINGS);
        $count = 0;

        foreach ($this->actionItems->all() as $item) {
            if ($item->status() === ActionStatus::Open) {
                $count++;
            }
        }

        return $count;
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

    private function requireMeeting(int $meetingId): Meeting
    {
        $meeting = $this->meetings->find($meetingId);

        if (! $meeting instanceof Meeting) {
            throw new \RuntimeException('Meeting was not found.');
        }

        return $meeting;
    }

    private function requireAgendaItem(int $meetingId, ?int $agendaItemId): void
    {
        if ($agendaItemId === null) {
            return;
        }

        $item = $this->agenda->find($agendaItemId);

        if ($item === null || $item->meetingId() !== $meetingId) {
            throw new MeetingRuleException('The agenda item does not belong to this meeting.');
        }
    }

    private function requirePerson(?int $personId): void
    {
        if ($personId === null) {
            return;
        }

        if (! $this->people->find($personId) instanceof Person) {
            throw new \RuntimeException('Person was not found.');
        }
    }

    private function requireNote(int $noteId): MeetingNote
    {
        $note = $this->notes->find($noteId);

        if (! $note instanceof MeetingNote) {
            throw new \RuntimeException('Note was not found.');
        }

        return $note;
    }

    private function requireDecision(int $decisionId): Decision
    {
        $decision = $this->decisions->find($decisionId);

        if (! $decision instanceof Decision) {
            throw new \RuntimeException('Decision was not found.');
        }

        return $decision;
    }

    private function requireActionItem(int $actionItemId): ActionItem
    {
        $item = $this->actionItems->find($actionItemId);

        if (! $item instanceof ActionItem) {
            throw new \RuntimeException('Action item was not found.');
        }

        return $item;
    }

    private function savedId(?int $id, string $message): int
    {
        if ($id === null) {
            throw new \RuntimeException($message);
        }

        return $id;
    }
}
