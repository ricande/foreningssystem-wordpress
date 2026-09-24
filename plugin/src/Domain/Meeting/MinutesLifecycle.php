<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

final class MinutesLifecycle
{
    public function submit(MinutesRevision $revision): MinutesRevision
    {
        if ($revision->state() !== RevisionState::Draft) {
            throw new MeetingRuleException('Only a draft can be sent for adjustment.');
        }

        return $revision->withState(RevisionState::UnderAdjustment);
    }

    public function sendBack(MinutesRevision $revision): MinutesRevision
    {
        if ($revision->state() !== RevisionState::UnderAdjustment) {
            throw new MeetingRuleException('Only a revision under adjustment can be sent back.');
        }

        return $revision->withState(RevisionState::Draft);
    }

    public function finalize(MinutesRevision $revision): MinutesRevision
    {
        if ($revision->state() !== RevisionState::UnderAdjustment) {
            throw new MeetingRuleException('Only a revision under adjustment can be finalized.');
        }

        return $revision->withState(RevisionState::Finalized);
    }

    public function correction(MinutesRevision $source, int $number): MinutesRevision
    {
        if ($source->state() !== RevisionState::Finalized || $source->supersededBy() !== null || $source->id() === null) {
            throw new MeetingRuleException('A correction starts from the current finalized revision.');
        }

        return new MinutesRevision(
            null,
            $source->minutesId(),
            $source->meetingId(),
            $number,
            RevisionState::Draft,
            $source->body(),
            $source->payload(),
            false,
            $source->id()
        );
    }

    public function supersede(MinutesRevision $previous, MinutesRevision $replacement): MinutesRevision
    {
        $replacementId = $replacement->id();

        if (
            $previous->state() !== RevisionState::Finalized
            || $previous->supersededBy() !== null
            || $replacement->state() !== RevisionState::Finalized
            || $replacementId === null
            || $replacement->correctsRevisionId() !== $previous->id()
        ) {
            throw new MeetingRuleException('The replacement does not correct this finalized revision.');
        }

        return $previous->markedSuperseded($replacementId);
    }
}
