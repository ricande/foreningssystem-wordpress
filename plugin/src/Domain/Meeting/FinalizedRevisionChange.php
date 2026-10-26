<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

final class FinalizedRevisionChange
{
    public static function assertAllowed(MinutesRevision $stored, MinutesRevision $next): void
    {
        if ($stored->state() !== RevisionState::Finalized) {
            // A revision is locked with the text that is stored. A request that read the
            // revision before someone else saved new text would otherwise lock the old text
            // and silently drop the last save.
            if (
                $next->state() === RevisionState::Finalized
                && ($stored->body() !== $next->body() || $stored->payload() !== $next->payload())
            ) {
                throw new MeetingRuleException('The minutes changed while they were being locked.');
            }

            return;
        }

        if (
            $stored->body() !== $next->body()
            || $stored->payload() !== $next->payload()
            || $stored->state() !== $next->state()
            || $stored->number() !== $next->number()
            || $stored->handEdited() !== $next->handEdited()
            || $stored->correctsRevisionId() !== $next->correctsRevisionId()
            || $stored->meetingId() !== $next->meetingId()
            || $stored->minutesId() !== $next->minutesId()
        ) {
            throw new \InvalidArgumentException('A finalized revision cannot change its text.');
        }
    }
}
