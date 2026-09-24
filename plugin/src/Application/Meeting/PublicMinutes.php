<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MinutesRevision;

final class PublicMinutes
{
    public function __construct(
        private readonly MinutesRevision $revision,
        private readonly Meeting $meeting,
    ) {
    }

    public function revision(): MinutesRevision
    {
        return $this->revision;
    }

    public function meeting(): Meeting
    {
        return $this->meeting;
    }
}
