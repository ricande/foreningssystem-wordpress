<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

final class AgendaOrder
{
    /**
     * @param list<AgendaItem> $items
     * @return list<AgendaItem>
     */
    public function sorted(array $items): array
    {
        usort(
            $items,
            static fn (AgendaItem $left, AgendaItem $right): int => $left->position() <=> $right->position()
                ?: (($left->id() ?? 0) <=> ($right->id() ?? 0))
        );

        return array_values($items);
    }

    /**
     * @param list<AgendaItem> $items
     */
    public function nextPosition(array $items): int
    {
        $max = 0;

        foreach ($items as $item) {
            $max = max($max, $item->position());
        }

        return $max + 1;
    }

    /**
     * @param list<AgendaItem> $items
     * @return list<AgendaItem>
     */
    public function move(array $items, int $itemId, int $direction): array
    {
        if ($direction !== -1 && $direction !== 1) {
            throw new \InvalidArgumentException('Agenda items move one step.');
        }

        $sorted = $this->sorted($items);
        $index = null;

        foreach ($sorted as $position => $item) {
            if ($item->id() === $itemId) {
                $index = $position;
            }
        }

        if ($index === null) {
            throw new \RuntimeException('Agenda item was not found.');
        }

        $target = $index + $direction;

        if (! isset($sorted[$target])) {
            throw new MeetingRuleException('The agenda item cannot move that way.');
        }

        return [
            $sorted[$index]->withPosition($sorted[$target]->position()),
            $sorted[$target]->withPosition($sorted[$index]->position()),
        ];
    }

    /**
     * @param list<AgendaItem> $items
     * @return list<AgendaItem>
     */
    public function remove(array $items, int $itemId): array
    {
        $remaining = [];
        $found = false;

        foreach ($this->sorted($items) as $item) {
            if ($item->id() === $itemId) {
                $found = true;
                continue;
            }

            $remaining[] = $item;
        }

        if (! $found) {
            throw new \RuntimeException('Agenda item was not found.');
        }

        $renumbered = [];
        $position = 1;

        foreach ($remaining as $item) {
            $renumbered[] = $item->withPosition($position);
            $position++;
        }

        return $renumbered;
    }
}
