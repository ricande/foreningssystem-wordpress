<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Settings\MinutesRoleReference;

final class WordpressMinutesRoleReference implements MinutesRoleReference
{
    public function references(string $slug): bool
    {
        return WordpressAccess::load()->grantsMinutes($slug);
    }
}
