<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

interface DecisionRepository
{
    public function add(Decision $decision): Decision;

    public function save(Decision $decision): void;

    public function remove(int $id): void;

    public function find(int $id): ?Decision;

    /**
     * @return list<Decision>
     */
    public function all(): array;

    /**
     * @return list<Decision>
     */
    public function forMeeting(int $meetingId): array;
}
