<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

interface MinutesRepository
{
    public function findDocumentId(int $meetingId): ?int;

    public function addDocument(int $meetingId): int;

    public function nextNumber(int $meetingId): int;

    public function addRevision(MinutesRevision $revision): MinutesRevision;

    public function saveRevision(MinutesRevision $revision): void;

    public function findRevision(int $id): ?MinutesRevision;

    public function openForMeeting(int $meetingId): ?MinutesRevision;

    public function latestForMeeting(int $meetingId): ?MinutesRevision;

    /**
     * @return list<MinutesRevision>
     */
    public function publicRevisions(): array;
}
