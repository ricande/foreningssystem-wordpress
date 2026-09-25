<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Document;

interface WordPressIdentity
{
    public function exists(int $userId): bool;
}
