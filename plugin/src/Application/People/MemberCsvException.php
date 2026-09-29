<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use InvalidArgumentException;

/**
 * A file-level CSV problem. The reason is a fixed code, never a cell value.
 */
final class MemberCsvException extends InvalidArgumentException
{
    public function __construct(private readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
