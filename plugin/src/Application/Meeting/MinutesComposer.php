<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\AgendaItem;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingNote;
use Foreningssystem\Domain\Meeting\Presence;
use JsonException;
use RuntimeException;

final class MinutesComposer
{
    /**
     * @param list<AttendanceRow> $attendance
     * @param list<AgendaItem> $agenda
     * @param list<MeetingNote> $notes
     * @param list<DecisionRow> $decisions
     * @param list<ActionItemRow> $actions
     */
    public function compose(
        Meeting $meeting,
        array $attendance,
        array $agenda,
        array $notes,
        array $decisions,
        array $actions,
    ): MinutesComposition {
        $lines = [$meeting->title(), $meeting->startsAt()->date() . ' ' . $meeting->startsAt()->time()];

        if ($meeting->place() !== '') {
            $lines[] = $meeting->place();
        }

        $lines[] = '';

        foreach ([
            [Presence::Present, 'Närvarande'],
            [Presence::Absent, 'Frånvarande'],
            [Presence::CoOpted, 'Adjungerade'],
        ] as [$presence, $heading]) {
            $names = [];

            foreach ($attendance as $row) {
                if ($row->participant()->presence() === $presence) {
                    $names[] = $row->personName();
                }
            }

            if ($names === []) {
                continue;
            }

            $lines[] = $heading;

            foreach ($names as $name) {
                $lines[] = $name;
            }

            $lines[] = '';
        }

        if ($agenda !== []) {
            $lines[] = 'Dagordning';
            $lines[] = '';

            foreach ($agenda as $item) {
                $lines[] = $item->displayNumber() . '. ' . $item->title();

                foreach ($this->notesFor($notes, $item->id()) as $note) {
                    $lines[] = 'Anteckning: ' . $note;
                }

                foreach ($this->decisionsFor($decisions, $item->id()) as $wording) {
                    $lines[] = 'Beslut: ' . $wording;
                }

                foreach ($this->actionsFor($actions, $item->id()) as $task) {
                    $lines[] = 'Uppgift: ' . $task;
                }

                $lines[] = '';
            }
        }

        $meetingNotes = $this->notesFor($notes, null);

        if ($meetingNotes !== []) {
            $lines[] = 'Övrigt';

            foreach ($meetingNotes as $note) {
                $lines[] = 'Anteckning: ' . $note;
            }

            $lines[] = '';
        }

        $closing = [];

        foreach ($attendance as $row) {
            if ($row->participant()->duty() === MeetingDuty::Chair) {
                $closing[] = 'Mötesordförande: ' . $row->personName();
            }
        }

        foreach ($attendance as $row) {
            if ($row->participant()->duty() === MeetingDuty::Adjuster) {
                $closing[] = 'Justerare: ' . $row->personName();
            }
        }

        if ($closing !== []) {
            $lines[] = 'Avslutning';

            foreach ($closing as $line) {
                $lines[] = $line;
            }
        }

        $payload = [
            'meeting' => [
                'title' => $meeting->title(),
                'startsAt' => $meeting->startsAt()->date() . ' ' . $meeting->startsAt()->time(),
                'place' => $meeting->place(),
            ],
            'attendance' => array_map(
                static fn (AttendanceRow $row): array => [
                    'name' => $row->personName(),
                    'presence' => $row->participant()->presence()->value,
                    'duty' => $row->participant()->duty()->value,
                ],
                $this->orderedAttendance($attendance)
            ),
            'agenda' => array_map(
                fn (AgendaItem $item): array => [
                    'number' => $item->displayNumber(),
                    'title' => $item->title(),
                    'notes' => $this->notesFor($notes, $item->id()),
                    'decisions' => $this->decisionsFor($decisions, $item->id()),
                    'actions' => $this->actionPayload($actions, $item->id()),
                ],
                $agenda
            ),
            'meetingNotes' => $meetingNotes,
        ];

        try {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('The minutes payload could not be stored.', 0, $error);
        }

        return new MinutesComposition(rtrim(implode("\n", $lines)), $encoded);
    }

    /**
     * @param list<AttendanceRow> $attendance
     * @return list<AttendanceRow>
     */
    private function orderedAttendance(array $attendance): array
    {
        $ordered = [];

        foreach ([Presence::Present, Presence::Absent, Presence::CoOpted] as $presence) {
            foreach ($attendance as $row) {
                if ($row->participant()->presence() === $presence) {
                    $ordered[] = $row;
                }
            }
        }

        return $ordered;
    }

    /**
     * @param list<MeetingNote> $notes
     * @return list<string>
     */
    private function notesFor(array $notes, ?int $agendaItemId): array
    {
        $bodies = [];

        foreach ($notes as $note) {
            if ($note->agendaItemId() === $agendaItemId && $note->includeInMinutes()) {
                $bodies[] = $note->body();
            }
        }

        return $bodies;
    }

    /**
     * @param list<DecisionRow> $decisions
     * @return list<string>
     */
    private function decisionsFor(array $decisions, ?int $agendaItemId): array
    {
        $wordings = [];

        foreach ($decisions as $row) {
            if ($row->decision()->agendaItemId() === $agendaItemId) {
                $wordings[] = $row->decision()->wording();
            }
        }

        return $wordings;
    }

    /**
     * @param list<ActionItemRow> $actions
     * @return list<string>
     */
    private function actionsFor(array $actions, ?int $agendaItemId): array
    {
        $lines = [];

        foreach ($this->actionPayload($actions, $agendaItemId) as $action) {
            $details = [];

            if ($action['assignee'] !== '') {
                $details[] = $action['assignee'];
            }

            if ($action['dueOn'] !== '') {
                $details[] = $action['dueOn'];
            }

            $details[] = $action['status'] === ActionStatus::Done->value ? 'klar' : 'öppen';
            $lines[] = $action['task'] . ' (' . implode(', ', $details) . ')';
        }

        return $lines;
    }

    /**
     * @param list<ActionItemRow> $actions
     * @return list<array{task: string, assignee: string, dueOn: string, status: string}>
     */
    private function actionPayload(array $actions, ?int $agendaItemId): array
    {
        $rows = [];

        foreach ($actions as $row) {
            $item = $row->item();

            if ($item->agendaItemId() !== $agendaItemId) {
                continue;
            }

            $rows[] = [
                'task' => $item->task(),
                'assignee' => $row->assigneeName() ?? '',
                'dueOn' => $item->dueOn()?->iso() ?? '',
                'status' => $item->status()->value,
            ];
        }

        return $rows;
    }
}
