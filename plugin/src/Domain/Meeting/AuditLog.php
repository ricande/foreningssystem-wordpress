<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Meeting;

interface AuditLog
{
    public function record(string $objectType, int $objectId, string $action, int $actorUserId): void;

    /**
     * @return list<AuditEvent>
     */
    public function forObject(string $objectType, int $objectId): array;

    public function forgetOnOrBefore(string $day): int;
}
