<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

interface Transaction
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed;
}
