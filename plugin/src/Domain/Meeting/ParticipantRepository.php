<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

interface ParticipantRepository
{
    public function add(Participant $participant): Participant;

    public function remove(int $id): void;

    public function find(int $id): ?Participant;

    /**
     * @return list<Participant>
     */
    public function forMeeting(int $meetingId): array;
}
