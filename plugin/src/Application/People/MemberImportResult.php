<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

final class MemberImportResult
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        private readonly int $created,
        private readonly int $skipped,
        private readonly array $errors,
    ) {
    }

    public function created(): int
    {
        return $this->created;
    }

    public function skipped(): int
    {
        return $this->skipped;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
