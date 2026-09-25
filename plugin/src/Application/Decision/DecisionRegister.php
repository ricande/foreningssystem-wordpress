<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Decision;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\AgendaItem;
use Foreningssystem\Domain\Meeting\AgendaRepository;
use Foreningssystem\Domain\Meeting\Decision;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Meeting\DecisionRepository;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingRepository;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;

final class DecisionRegister
{
    public function __construct(
        private readonly DecisionRepository $decisions,
        private readonly MeetingRepository $meetings,
        private readonly AgendaRepository $agenda,
        private readonly PersonRepository $people,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function snapshot(AssociationDate $today, DecisionRegisterQuery $query): DecisionRegisterSnapshot
    {
        if (! $this->authorizer->allows(Capabilities::VIEW_INTERNAL_MEETINGS)) {
            throw new NotAllowed(Capabilities::VIEW_INTERNAL_MEETINGS);
        }

        $decisions = $this->decisions->all();
        $meetings = $this->meetingsById();
        $people = $this->peopleById();
        $agenda = $this->agendaById($decisions);
        $rows = [];

        foreach ($decisions as $decision) {
            $id = $decision->id();

            if ($id === null) {
                continue;
            }

            $rows[] = $this->row($decision, $id, $today, $meetings, $people, $agenda);
        }

        $openCount = 0;
        $overdueCount = 0;
        $doneCount = 0;

        foreach ($rows as $row) {
            if ($row->followUp === DecisionFollowUp::Open) {
                $openCount++;

                if ($row->overdue) {
                    $overdueCount++;
                }
            } else {
                $doneCount++;
            }
        }

        $matched = array_values(array_filter(
            $rows,
            static fn (DecisionRegisterRow $row): bool => self::matches($row, $query)
        ));
        usort($matched, static fn (DecisionRegisterRow $left, DecisionRegisterRow $right): int => self::compare($left, $right));

        $pages = max(1, (int) ceil(count($matched) / DecisionRegisterQuery::PAGE_SIZE));
        $page = min($query->page, $pages);
        $offset = ($page - 1) * DecisionRegisterQuery::PAGE_SIZE;

        return new DecisionRegisterSnapshot(
            $openCount,
            $overdueCount,
            $doneCount,
            count($matched),
            $page,
            $pages,
            array_values(array_slice($matched, $offset, DecisionRegisterQuery::PAGE_SIZE)),
            $this->choices($rows),
            $query
        );
    }

    /**
     * @param array<int, Meeting> $meetings
     * @param array<int, Person> $people
     * @param array<int, AgendaItem> $agenda
     */
    private function row(
        Decision $decision,
        int $id,
        AssociationDate $today,
        array $meetings,
        array $people,
        array $agenda,
    ): DecisionRegisterRow {
        $meeting = $meetings[$decision->meetingId()] ?? null;
        $personId = $decision->responsiblePersonId();
        $person = $personId !== null ? ($people[$personId] ?? null) : null;
        $deadline = $decision->deadline()?->iso();
        $overdue = $decision->followUp() === DecisionFollowUp::Open
            && $deadline !== null
            && $decision->deadline()->isBefore($today);
        $itemId = $decision->agendaItemId();
        $item = $itemId !== null ? ($agenda[$itemId] ?? null) : null;

        if ($item !== null && $item->meetingId() !== $decision->meetingId()) {
            $item = null;
        }

        if ($personId === null) {
            $responsibleState = ResponsiblePresentation::Unassigned;
            $responsibleName = null;
        } elseif (! $person instanceof Person) {
            $responsibleState = ResponsiblePresentation::Unavailable;
            $responsibleName = null;
        } else {
            $responsibleState = ResponsiblePresentation::Assigned;
            $responsibleName = $person->firstName() . ' ' . $person->lastName();
        }

        if ($itemId === null) {
            $agendaState = AgendaPresentation::None;
        } elseif (! $item instanceof AgendaItem) {
            $agendaState = AgendaPresentation::Unavailable;
        } else {
            $agendaState = AgendaPresentation::Item;
        }

        return new DecisionRegisterRow(
            $id,
            $decision->wording(),
            $decision->followUp(),
            $personId,
            $responsibleName,
            $responsibleState,
            $deadline,
            $overdue,
            $decision->meetingId(),
            $meeting instanceof Meeting,
            $meeting instanceof Meeting ? $meeting->title() : null,
            $meeting instanceof Meeting ? $meeting->startsAt()->date() : null,
            $meeting instanceof Meeting ? $meeting->status() : null,
            $itemId,
            $agendaState,
            $item instanceof AgendaItem ? $item->displayNumber() : null,
            $item instanceof AgendaItem ? $item->title() : null,
        );
    }

    /**
     * @return array<int, Meeting>
     */
    private function meetingsById(): array
    {
        $meetings = [];

        foreach ($this->meetings->all() as $meeting) {
            $id = $meeting->id();

            if ($id !== null) {
                $meetings[$id] = $meeting;
            }
        }

        return $meetings;
    }

    /**
     * @return array<int, Person>
     */
    private function peopleById(): array
    {
        $people = [];

        foreach ($this->people->all() as $person) {
            $id = $person->id();

            if ($id !== null) {
                $people[$id] = $person;
            }
        }

        return $people;
    }

    /**
     * @param list<Decision> $decisions
     * @return array<int, AgendaItem>
     */
    private function agendaById(array $decisions): array
    {
        $meetingIds = [];

        foreach ($decisions as $decision) {
            $meetingIds[$decision->meetingId()] = true;
        }

        $agenda = [];

        foreach (array_keys($meetingIds) as $meetingId) {
            foreach ($this->agenda->forMeeting($meetingId) as $item) {
                $id = $item->id();

                if ($id !== null) {
                    $agenda[$id] = $item;
                }
            }
        }

        return $agenda;
    }

    private static function matches(DecisionRegisterRow $row, DecisionRegisterQuery $query): bool
    {
        if ($query->followUp === DecisionRegisterQuery::OPEN && $row->followUp !== DecisionFollowUp::Open) {
            return false;
        }

        if ($query->followUp === DecisionRegisterQuery::DONE && $row->followUp !== DecisionFollowUp::Done) {
            return false;
        }

        if ($query->responsible === DecisionRegisterQuery::UNASSIGNED && $row->responsibleState !== ResponsiblePresentation::Unassigned) {
            return false;
        }

        if ($query->responsible !== DecisionRegisterQuery::ALL && $query->responsible !== DecisionRegisterQuery::UNASSIGNED) {
            if ($row->responsiblePersonId !== (int) $query->responsible) {
                return false;
            }
        }

        if ($query->urgency === DecisionRegisterQuery::OVERDUE && ! $row->overdue) {
            return false;
        }

        return true;
    }

    private static function compare(DecisionRegisterRow $left, DecisionRegisterRow $right): int
    {
        $leftOpen = $left->followUp === DecisionFollowUp::Open;
        $rightOpen = $right->followUp === DecisionFollowUp::Open;

        if ($leftOpen !== $rightOpen) {
            return $leftOpen ? -1 : 1;
        }

        if ($leftOpen) {
            $rank = self::openRank($left) <=> self::openRank($right);

            if ($rank !== 0) {
                return $rank;
            }

            if (self::openRank($left) === 2) {
                return self::compareDatesDesc($left->meetingDate, $right->meetingDate)
                    ?: ($right->decisionId <=> $left->decisionId);
            }

            return ($left->deadline <=> $right->deadline)
                ?: self::compareDatesDesc($left->meetingDate, $right->meetingDate)
                ?: ($left->decisionId <=> $right->decisionId);
        }

        return self::compareDatesDesc($left->meetingDate, $right->meetingDate)
            ?: ($right->decisionId <=> $left->decisionId);
    }

    private static function openRank(DecisionRegisterRow $row): int
    {
        if ($row->overdue) {
            return 0;
        }

        return $row->deadline !== null ? 1 : 2;
    }

    private static function compareDatesDesc(?string $left, ?string $right): int
    {
        return ($right ?? '') <=> ($left ?? '');
    }

    /**
     * @param list<DecisionRegisterRow> $rows
     * @return list<array{id: int, name: string}>
     */
    private function choices(array $rows): array
    {
        $choices = [];

        foreach ($rows as $row) {
            if ($row->responsibleState !== ResponsiblePresentation::Assigned || $row->responsiblePersonId === null || $row->responsibleName === null) {
                continue;
            }

            $choices[$row->responsiblePersonId] = [
                'id' => $row->responsiblePersonId,
                'name' => $row->responsibleName,
            ];
        }

        $choices = array_values($choices);
        usort($choices, static fn (array $left, array $right): int => [$left['name'], $left['id']] <=> [$right['name'], $right['id']]);

        return $choices;
    }
}
