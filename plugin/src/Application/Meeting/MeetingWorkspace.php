<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\ActionItemRepository;
use Foreningssystem\Domain\Meeting\AgendaItem;
use Foreningssystem\Domain\Meeting\AgendaOrder;
use Foreningssystem\Domain\Meeting\AgendaRepository;
use Foreningssystem\Domain\Meeting\DecisionRepository;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingNoteRepository;
use Foreningssystem\Domain\Meeting\MeetingRepository;
use Foreningssystem\Domain\Meeting\MeetingRoster;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\Participant;
use Foreningssystem\Domain\Meeting\ParticipantRepository;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;
use Foreningssystem\Domain\Person\PersonStatus;

final class MeetingWorkspace
{
    public function __construct(
        private readonly MeetingRepository $meetings,
        private readonly PersonRepository $people,
        private readonly ParticipantRepository $participants,
        private readonly AgendaRepository $agenda,
        private readonly MeetingNoteRepository $notes,
        private readonly DecisionRepository $decisions,
        private readonly ActionItemRepository $actionItems,
        private readonly MeetingRoster $roster,
        private readonly AgendaOrder $order,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
    ) {
    }

    public function addParticipant(int $meetingId, int $personId, Presence $presence, MeetingDuty $duty): void
    {
        $this->requireEdit();
        $this->requireMeeting($meetingId);
        $this->requirePerson($personId);

        $this->transaction->run(function () use ($meetingId, $personId, $presence, $duty): void {
            $this->roster->assertNew($this->participants->forMeeting($meetingId), $personId);
            $this->participants->add(new Participant(null, $meetingId, $personId, $presence, $duty));
        });
    }

    public function removeParticipant(int $participantId, ?int $meetingId = null): void
    {
        $this->requireEdit();
        $participant = $this->requireParticipant($participantId);
        $this->assertSameMeeting($participant->meetingId(), $meetingId);

        $this->transaction->run(function () use ($participant): void {
            $id = $participant->id();

            if ($id === null) {
                throw new \RuntimeException('Participant was not saved.');
            }

            $this->participants->remove($id);
        });
    }

    public function addAgendaItem(int $meetingId, string $title, string $numberOverride): int
    {
        $this->requireEdit();
        $this->requireMeeting($meetingId);

        $saved = $this->transaction->run(function () use ($meetingId, $title, $numberOverride): AgendaItem {
            $position = $this->order->nextPosition($this->agenda->forMeeting($meetingId));

            return $this->agenda->add(new AgendaItem(null, $meetingId, $position, $title, $numberOverride));
        });
        $id = $saved->id();

        if ($id === null) {
            throw new \RuntimeException('The agenda item was not saved.');
        }

        return $id;
    }

    public function moveAgendaItem(int $itemId, int $direction, ?int $meetingId = null): void
    {
        $this->requireEdit();
        $item = $this->requireAgendaItem($itemId);
        $this->assertSameMeeting($item->meetingId(), $meetingId);

        $this->transaction->run(function () use ($item, $direction): void {
            foreach ($this->order->move($this->agenda->forMeeting($item->meetingId()), (int) $item->id(), $direction) as $changed) {
                $this->agenda->save($changed);
            }
        });
    }

    public function removeAgendaItem(int $itemId, ?int $meetingId = null): void
    {
        $this->requireEdit();
        $item = $this->requireAgendaItem($itemId);
        $this->assertSameMeeting($item->meetingId(), $meetingId);
        $this->assertNoLinkedRecords($item);

        $this->transaction->run(function () use ($item): void {
            $remaining = $this->order->remove($this->agenda->forMeeting($item->meetingId()), (int) $item->id());
            $this->agenda->remove((int) $item->id());

            foreach ($remaining as $kept) {
                $this->agenda->save($kept);
            }
        });
    }

    /**
     * @return list<AttendanceRow>
     */
    public function attendance(int $meetingId): array
    {
        $this->requireView();
        $this->requireMeeting($meetingId);
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
     * @return list<AgendaItem>
     */
    public function agenda(int $meetingId): array
    {
        $this->requireView();
        $this->requireMeeting($meetingId);

        return $this->order->sorted($this->agenda->forMeeting($meetingId));
    }

    private function requireView(): void
    {
        $this->require(Capabilities::VIEW_INTERNAL_MEETINGS);
    }

    private function requireEdit(): void
    {
        if ($this->authorizer->allows(Capabilities::RECORD_MEETING) || $this->authorizer->allows(Capabilities::MANAGE_MEETINGS)) {
            return;
        }

        throw new NotAllowed(Capabilities::RECORD_MEETING);
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

    private function requirePerson(int $personId): Person
    {
        $person = $this->people->find($personId);

        if (! $person instanceof Person) {
            throw new \RuntimeException('Person was not found.');
        }

        if ($person->status() === PersonStatus::Deceased) {
            throw new MeetingRuleException('A deceased person cannot be added to a meeting.');
        }

        return $person;
    }

    private function assertSameMeeting(int $actualMeetingId, ?int $meetingId): void
    {
        if ($meetingId !== null && $actualMeetingId !== $meetingId) {
            throw new MeetingRuleException('The record does not belong to this meeting.');
        }
    }

    private function assertNoLinkedRecords(AgendaItem $item): void
    {
        $itemId = $item->id();

        foreach ($this->notes->forMeeting($item->meetingId()) as $note) {
            if ($note->agendaItemId() === $itemId) {
                throw new MeetingRuleException('Remove the notes, decisions, and tasks on this item before removing it.');
            }
        }

        foreach ($this->decisions->forMeeting($item->meetingId()) as $decision) {
            if ($decision->agendaItemId() === $itemId) {
                throw new MeetingRuleException('Remove the notes, decisions, and tasks on this item before removing it.');
            }
        }

        foreach ($this->actionItems->forMeeting($item->meetingId()) as $action) {
            if ($action->agendaItemId() === $itemId) {
                throw new MeetingRuleException('Remove the notes, decisions, and tasks on this item before removing it.');
            }
        }
    }

    private function requireParticipant(int $participantId): Participant
    {
        $participant = $this->participants->find($participantId);

        if (! $participant instanceof Participant) {
            throw new \RuntimeException('Participant was not found.');
        }

        return $participant;
    }

    private function requireAgendaItem(int $itemId): AgendaItem
    {
        $item = $this->agenda->find($itemId);

        if (! $item instanceof AgendaItem) {
            throw new \RuntimeException('Agenda item was not found.');
        }

        return $item;
    }
}
