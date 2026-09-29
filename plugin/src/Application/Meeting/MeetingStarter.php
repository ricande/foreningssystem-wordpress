<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

/**
 * A starting point shipped with the plugin. It is copied into an association template and is not stored as one.
 */
final class MeetingStarter
{
    /**
     * @param list<string> $headings
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $typeSlug,
        private readonly array $headings,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function typeSlug(): string
    {
        return $this->typeSlug;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }
}
