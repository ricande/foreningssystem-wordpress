<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use Foreningssystem\Domain\Meeting\Participant;

final class AttendanceRow
{
    public function __construct(
        private readonly Participant $participant,
        private readonly string $personName,
    ) {
    }

    public function participant(): Participant
    {
        return $this->participant;
    }

    public function personName(): string
    {
        return $this->personName;
    }
}
