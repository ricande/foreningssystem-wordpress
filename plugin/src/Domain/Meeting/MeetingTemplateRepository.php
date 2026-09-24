<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

interface MeetingTemplateRepository
{
    public function add(MeetingTemplate $template): MeetingTemplate;

    public function remove(int $id): void;

    public function find(int $id): ?MeetingTemplate;

    /**
     * @return list<MeetingTemplate>
     */
    public function all(): array;
}
