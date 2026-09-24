<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingRepository;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MinutesRepository;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\PublicationVisibility;
use Foreningssystem\Domain\Meeting\RevisionState;

final class MinutesPublication
{
    public function __construct(
        private readonly MeetingRepository $meetings,
        private readonly MinutesRepository $minutes,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
    ) {
    }

    public function publish(int $revisionId): void
    {
        $this->requirePublish();
        $revision = $this->requireCurrentFinalized($revisionId);

        $this->transaction->run(function () use ($revision): void {
            $this->minutes->saveRevision($revision->withVisibility(PublicationVisibility::Public));
        });
    }

    public function unpublish(int $revisionId): void
    {
        $this->requirePublish();
        $revision = $this->requireCurrentFinalized($revisionId);

        $this->transaction->run(function () use ($revision): void {
            $this->minutes->saveRevision($revision->withVisibility(PublicationVisibility::Board));
        });
    }

    public function latest(): ?PublicMinutes
    {
        $chosen = null;
        $chosenMeeting = null;

        foreach ($this->minutes->publicRevisions() as $revision) {
            if (! $this->isCurrentPublic($revision)) {
                continue;
            }

            $meeting = $this->meetings->find($revision->meetingId());

            if (! $meeting instanceof Meeting) {
                continue;
            }

            if ($chosen === null || $chosenMeeting === null || $this->isLater($meeting, $revision, $chosenMeeting, $chosen)) {
                $chosen = $revision;
                $chosenMeeting = $meeting;
            }
        }

        if (! $chosen instanceof MinutesRevision || ! $chosenMeeting instanceof Meeting) {
            return null;
        }

        return new PublicMinutes($chosen, $chosenMeeting);
    }

    private function isLater(Meeting $candidateMeeting, MinutesRevision $candidate, Meeting $currentMeeting, MinutesRevision $current): bool
    {
        $byTime = strcmp($candidateMeeting->startsAt()->local(), $currentMeeting->startsAt()->local());

        if ($byTime !== 0) {
            return $byTime > 0;
        }

        if ($candidate->meetingId() !== $current->meetingId()) {
            return $candidate->meetingId() > $current->meetingId();
        }

        return $candidate->number() > $current->number();
    }

    private function isCurrentPublic(MinutesRevision $revision): bool
    {
        return $revision->state() === RevisionState::Finalized
            && $revision->visibility() === PublicationVisibility::Public
            && $revision->supersededBy() === null;
    }

    private function requireCurrentFinalized(int $revisionId): MinutesRevision
    {
        $revision = $this->minutes->findRevision($revisionId);

        if (! $revision instanceof MinutesRevision) {
            throw new \RuntimeException('Minutes revision was not found.');
        }

        if ($revision->state() !== RevisionState::Finalized || $revision->supersededBy() !== null) {
            throw new MeetingRuleException('Only the current finalized revision can be published.');
        }

        return $revision;
    }

    private function requirePublish(): void
    {
        if (! $this->authorizer->allows(Capabilities::PUBLISH_MINUTES)) {
            throw new NotAllowed(Capabilities::PUBLISH_MINUTES);
        }
    }
}
