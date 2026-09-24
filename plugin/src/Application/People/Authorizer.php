<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

interface Authorizer
{
    public function allows(string $capability): bool;
}
