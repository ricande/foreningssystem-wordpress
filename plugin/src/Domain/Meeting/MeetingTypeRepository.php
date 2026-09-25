<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

interface MeetingTypeRepository
{
    public function add(MeetingType $type): MeetingType;

    public function save(MeetingType $type): void;

    public function find(int $id): ?MeetingType;

    public function findBySlug(string $slug): ?MeetingType;

    /**
     * @return list<MeetingType>
     */
    public function all(): array;
}
