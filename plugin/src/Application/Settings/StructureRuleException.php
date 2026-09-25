<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Settings;

use RuntimeException;

final class StructureRuleException extends RuntimeException
{
    public const DUPLICATE = 'duplicate';

    public const BUILTIN = 'builtin';

    public const USED = 'used';

    public const MISSING = 'missing';

    public const INVALID = 'invalid';

    public function __construct(string $rule)
    {
        parent::__construct($rule);
    }

    public function rule(): string
    {
        return $this->getMessage();
    }
}
