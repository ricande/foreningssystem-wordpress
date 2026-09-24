<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

final class FinalizedRevisionChange
{
    public static function assertAllowed(MinutesRevision $stored, MinutesRevision $next): void
    {
        if ($stored->state() !== RevisionState::Finalized) {
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
