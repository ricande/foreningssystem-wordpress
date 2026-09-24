<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

interface AgendaRepository
{
    public function add(AgendaItem $item): AgendaItem;

    public function save(AgendaItem $item): void;

    public function remove(int $id): void;

    public function find(int $id): ?AgendaItem;

    /**
     * @return list<AgendaItem>
     */
    public function forMeeting(int $meetingId): array;
}
