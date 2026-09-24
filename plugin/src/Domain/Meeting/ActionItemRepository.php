<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

interface ActionItemRepository
{
    public function add(ActionItem $item): ActionItem;

    public function save(ActionItem $item): void;

    public function remove(int $id): void;

    public function find(int $id): ?ActionItem;

    /**
     * @return list<ActionItem>
     */
    public function all(): array;

    /**
     * @return list<ActionItem>
     */
    public function forMeeting(int $meetingId): array;
}
