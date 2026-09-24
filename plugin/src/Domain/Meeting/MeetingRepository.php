<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

interface MeetingRepository
{
    public function add(Meeting $meeting): Meeting;

    public function save(Meeting $meeting): void;

    public function find(int $id): ?Meeting;

    /**
     * @return list<Meeting>
     */
    public function all(): array;
}
