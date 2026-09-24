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
use Foreningssystem\Domain\Meeting\MinutesLifecycle;
use Foreningssystem\Domain\Meeting\RevisionNumberTaken;
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
        private readonly MinutesLifecycle $lifecycle,
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

        $saved = $this->once(function () use ($meeting, $composition): MinutesRevision {
            return $this->transaction->run(function () use ($meeting, $composition): MinutesRevision {
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

        return $this->minutes->latestForMeeting($meetingId);
    }

    public function revision(int $revisionId): MinutesRevision
    {
        $this->require(Capabilities::VIEW_INTERNAL_MEETINGS);
        $revision = $this->minutes->findRevision($revisionId);

        if (! $revision instanceof MinutesRevision) {
            throw new \RuntimeException('Minutes revision was not found.');
        }

        return $revision;
    }

    public function isStale(int $meetingId): bool
    {
        $draft = $this->current($meetingId);

        if (! $draft instanceof MinutesRevision || $draft->state() === RevisionState::Finalized) {
            return false;
        }

        return $this->composition($this->requireMeeting($meetingId))->payload() !== $draft->payload();
    }

    public function replaceBody(int $revisionId, string $body): void
    {
        $this->requireRecord();
        $draft = $this->requireEditable($revisionId);

        $this->transaction->run(function () use ($draft, $body): void {
            $this->minutes->saveRevision($draft->withBody($body));
        });
    }

    public function regenerate(int $revisionId, bool $confirmed): void
    {
        $this->requireRecord();
        $draft = $this->requireEditable($revisionId);

        if ($draft->handEdited() && ! $confirmed) {
            throw new MeetingRuleException('Confirm before replacing a hand-edited draft.');
        }

        $composition = $this->composition($this->requireMeeting($draft->meetingId()));

        $this->transaction->run(function () use ($draft, $composition): void {
            $this->minutes->saveRevision($draft->regenerated($composition->body(), $composition->payload()));
        });
    }

    public function submit(int $revisionId): void
    {
        $this->requireRecord();
        $draft = $this->requireEditable($revisionId);

        $this->transaction->run(function () use ($draft): void {
            $this->minutes->saveRevision($this->lifecycle->submit($draft));
        });
    }

    public function sendBack(int $revisionId): void
    {
        $this->requireRecord();
        $draft = $this->requireEditable($revisionId);

        $this->transaction->run(function () use ($draft): void {
            $this->minutes->saveRevision($this->lifecycle->sendBack($draft));
        });
    }

    public function finalize(int $revisionId): void
    {
        $this->require(Capabilities::FINALIZE_MINUTES);
        $revision = $this->requireRevision($revisionId);

        $this->transaction->run(function () use ($revision): void {
            $finalized = $this->lifecycle->finalize($revision);
            $this->minutes->saveRevision($finalized);
            $corrects = $finalized->correctsRevisionId();

            if ($corrects === null) {
                return;
            }

            $previous = $this->minutes->findRevision($corrects);

            if (! $previous instanceof MinutesRevision) {
                throw new \RuntimeException('The corrected revision was not found.');
            }

            $this->minutes->saveRevision($this->lifecycle->supersede($previous, $finalized));
        });
    }

    public function openCorrection(int $meetingId): int
    {
        $this->require(Capabilities::FINALIZE_MINUTES);
        $this->requireHeld($meetingId);
        $this->requireNoOpenRevision($meetingId);
        $source = $this->requireCurrentFinalized($meetingId);

        $saved = $this->once(function () use ($meetingId, $source): MinutesRevision {
            return $this->transaction->run(function () use ($meetingId, $source): MinutesRevision {
                $this->requireNoOpenRevision($meetingId);
                $current = $this->minutes->findRevision((int) $source->id());

                if (! $current instanceof MinutesRevision) {
                    throw new \RuntimeException('Minutes revision was not found.');
                }

                return $this->minutes->addRevision($this->lifecycle->correction($current, $this->minutes->nextNumber($meetingId)));
            });
        });

        $id = $saved->id();

        if ($id === null) {
            throw new \RuntimeException('The correction was not saved.');
        }

        return $id;
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
        if ($this->minutes->latestForMeeting($meetingId) instanceof MinutesRevision) {
            throw new MeetingRuleException('This meeting already has minutes.');
        }
    }

    private function requireNoOpenRevision(int $meetingId): void
    {
        if ($this->minutes->openForMeeting($meetingId) instanceof MinutesRevision) {
            throw new MeetingRuleException('This meeting already has an open revision.');
        }
    }

    private function requireCurrentFinalized(int $meetingId): MinutesRevision
    {
        $revision = $this->minutes->latestForMeeting($meetingId);

        if (! $revision instanceof MinutesRevision || $revision->state() !== RevisionState::Finalized || $revision->supersededBy() !== null) {
            throw new MeetingRuleException('A correction starts from the current finalized revision.');
        }

        return $revision;
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function once(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RevisionNumberTaken) {
            return $operation();
        }
    }

    private function requireEditable(int $revisionId): MinutesRevision
    {
        $revision = $this->requireRevision($revisionId);

        if ($revision->state() !== RevisionState::Draft && $revision->state() !== RevisionState::UnderAdjustment) {
            throw new MeetingRuleException('A finalized revision cannot be edited.');
        }

        return $revision;
    }

    private function requireRevision(int $revisionId): MinutesRevision
    {
        $revision = $this->minutes->findRevision($revisionId);

        if (! $revision instanceof MinutesRevision) {
            throw new \RuntimeException('Minutes revision was not found.');
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
