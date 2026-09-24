<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

final class AuditEvent
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $objectType,
        private readonly int $objectId,
        private readonly string $action,
        private readonly int $actorUserId,
        private readonly string $createdAt,
    ) {
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function objectType(): string
    {
        return $this->objectType;
    }

    public function objectId(): int
    {
        return $this->objectId;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function actorUserId(): int
    {
        return $this->actorUserId;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }
}
