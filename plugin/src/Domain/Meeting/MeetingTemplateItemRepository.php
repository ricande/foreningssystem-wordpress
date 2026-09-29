<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

interface MeetingTemplateItemRepository
{
    public function add(MeetingTemplateItem $item): MeetingTemplateItem;

    public function save(MeetingTemplateItem $item): void;

    public function remove(int $id): void;

    public function find(int $id): ?MeetingTemplateItem;

    /**
     * @return list<MeetingTemplateItem>
     */
    public function forTemplate(int $templateId): array;
}
