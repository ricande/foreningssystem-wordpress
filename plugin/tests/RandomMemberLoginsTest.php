<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Account\RandomMemberLogins;
use PHPUnit\Framework\TestCase;

final class RandomMemberLoginsTest extends TestCase
{
    public function test_a_login_is_the_prefix_and_sixteen_hex_characters(): void
    {
        $login = (new RandomMemberLogins())->next();

        self::assertMatchesRegularExpression('/^assoc-member-[0-9a-f]{16}$/', $login);
    }

    public function test_a_login_fits_the_wordpress_login_and_nicename_columns(): void
    {
        // user_login is VARCHAR(60) and user_nicename, which WordPress derives from it, is VARCHAR(50).
        self::assertLessThanOrEqual(50, strlen((new RandomMemberLogins())->next()));
    }

    public function test_logins_do_not_repeat_or_count_upwards(): void
    {
        $logins = new RandomMemberLogins();
        $drawn = [];

        for ($i = 0; $i < 500; $i++) {
            $drawn[] = $logins->next();
        }

        self::assertCount(500, array_unique($drawn));
        self::assertNotContains('assoc-member-1', $drawn);
    }
}
