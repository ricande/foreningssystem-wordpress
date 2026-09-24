<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Document;

interface ActiveMember
{
    public function coversCurrentUser(): bool;
}
