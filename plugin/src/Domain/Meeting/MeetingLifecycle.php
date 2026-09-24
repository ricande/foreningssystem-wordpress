<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

final class MeetingLifecycle
{
    public function start(Meeting $meeting): Meeting
    {
        if ($meeting->status() !== MeetingStatus::Planned) {
            throw new MeetingRuleException('Only a planned meeting can be started.');
        }

        return $meeting->withStatus(MeetingStatus::InProgress);
    }

    public function markHeld(Meeting $meeting): Meeting
    {
        if ($meeting->status() === MeetingStatus::Held) {
            throw new MeetingRuleException('The meeting is already held.');
        }

        if ($meeting->status() !== MeetingStatus::Planned && $meeting->status() !== MeetingStatus::InProgress) {
            throw new MeetingRuleException('Only a planned or ongoing meeting can be marked held.');
        }

        return $meeting->withStatus(MeetingStatus::Held);
    }
}
