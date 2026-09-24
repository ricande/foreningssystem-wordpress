<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

use InvalidArgumentException;

final class MeetingNote
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $meetingId,
        private readonly ?int $agendaItemId,
        string $body,
        private readonly bool $includeInMinutes,
    ) {
        $this->body = trim($body);

        if ($this->meetingId < 1 || ($this->agendaItemId !== null && $this->agendaItemId < 1)) {
            throw new InvalidArgumentException('A note belongs to a saved meeting.');
        }

        if ($this->body === '' || strlen($this->body) > 4000) {
            throw new InvalidArgumentException('A note needs text.');
        }
    }

    private readonly string $body;

    public function id(): ?int
    {
        return $this->id;
    }

    public function meetingId(): int
    {
        return $this->meetingId;
    }

    public function agendaItemId(): ?int
    {
        return $this->agendaItemId;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function includeInMinutes(): bool
    {
        return $this->includeInMinutes;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->meetingId, $this->agendaItemId, $this->body, $this->includeInMinutes);
    }

    public function revised(string $body, bool $includeInMinutes): self
    {
        return new self($this->id, $this->meetingId, $this->agendaItemId, $body, $includeInMinutes);
    }
}
