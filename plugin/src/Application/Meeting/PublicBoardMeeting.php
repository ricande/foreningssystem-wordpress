<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

final class PublicBoardMeeting
{
    public function __construct(
        private readonly string $title,
        private readonly string $date,
        private readonly string $place,
    ) {
    }

    public function title(): string
    {
        return $this->title;
    }

    public function date(): string
    {
        return $this->date;
    }

    public function place(): string
    {
        return $this->place;
    }
}
