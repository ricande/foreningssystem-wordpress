<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

final class MinutesComposition
{
    public function __construct(
        private readonly string $body,
        private readonly string $payload,
    ) {
    }

    public function body(): string
    {
        return $this->body;
    }

    public function payload(): string
    {
        return $this->payload;
    }
}
