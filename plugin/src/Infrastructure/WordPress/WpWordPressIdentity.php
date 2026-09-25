<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Document\WordPressIdentity;
use WP_User;

final class WpWordPressIdentity implements WordPressIdentity
{
    public function exists(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        return get_userdata($userId) instanceof WP_User;
    }
}
