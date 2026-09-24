<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

final class MeetingRoster
{
    /**
     * @param list<Participant> $existing
     */
    public function assertNew(array $existing, int $personId): void
    {
        foreach ($existing as $participant) {
            if ($participant->personId() === $personId) {
                throw new MeetingRuleException('This person is already on the meeting.');
            }
        }
    }
}
