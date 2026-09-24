<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

interface MeetingNoteRepository
{
    public function add(MeetingNote $note): MeetingNote;

    public function save(MeetingNote $note): void;

    public function remove(int $id): void;

    public function find(int $id): ?MeetingNote;

    /**
     * @return list<MeetingNote>
     */
    public function forMeeting(int $meetingId): array;
}
