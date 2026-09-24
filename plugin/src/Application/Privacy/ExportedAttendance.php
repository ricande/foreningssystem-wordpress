<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Privacy;

final class ExportedAttendance
{
    public function __construct(
        private readonly int $participantId,
        private readonly string $meetingTitle,
        private readonly string $meetingDate,
        private readonly string $presence,
        private readonly string $duty,
    ) {
    }

    public function participantId(): int
    {
        return $this->participantId;
    }

    public function meetingTitle(): string
    {
        return $this->meetingTitle;
    }

    public function meetingDate(): string
    {
        return $this->meetingDate;
    }

    public function presence(): string
    {
        return $this->presence;
    }

    public function duty(): string
    {
        return $this->duty;
    }
}
