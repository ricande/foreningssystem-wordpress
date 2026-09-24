<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use Foreningssystem\Domain\Meeting\ActionItem;

final class ActionItemRow
{
    public function __construct(
        private readonly ActionItem $item,
        private readonly ?string $assigneeName,
    ) {
    }

    public function item(): ActionItem
    {
        return $this->item;
    }

    public function assigneeName(): ?string
    {
        return $this->assigneeName;
    }
}
