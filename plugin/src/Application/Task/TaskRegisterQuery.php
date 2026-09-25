<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Task;

use Foreningssystem\Domain\Meeting\ActionStatus;
use InvalidArgumentException;

final class TaskRegisterQuery
{
    public const PAGE_SIZE = 50;

    public const OPEN = 'open';

    public const DONE = 'done';

    public const ALL = 'all';

    public const UNASSIGNED = 'unassigned';

    public const OVERDUE = 'overdue';

    public function __construct(
        public readonly string $status,
        public readonly string $assignee,
        public readonly string $urgency,
        public readonly int $page,
    ) {
    }

    public static function defaults(): self
    {
        return self::normalize(null, null, null, 1);
    }

    public static function normalize(?string $status, ?string $assignee, ?string $urgency, int $page): self
    {
        $status = in_array($status, [self::OPEN, self::DONE, self::ALL], true) ? $status : self::OPEN;
        $urgency = $urgency === self::OVERDUE ? self::OVERDUE : self::ALL;

        if ($assignee === self::UNASSIGNED) {
            $assigneeValue = self::UNASSIGNED;
        } elseif (is_string($assignee) && ctype_digit($assignee) && (int) $assignee >= 1) {
            $assigneeValue = (string) (int) $assignee;
        } else {
            $assigneeValue = self::ALL;
        }

        return new self($status, $assigneeValue, $urgency, max(1, $page));
    }

    public static function statusChange(string $value): ActionStatus
    {
        $parsed = ActionStatus::tryFrom($value);

        if ($parsed === null) {
            throw new InvalidArgumentException('Task status is not valid.');
        }

        return $parsed;
    }
}
