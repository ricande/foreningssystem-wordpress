<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Setup;

final class SetupStep
{
    public const WELCOME = 'welcome';

    public const ASSOCIATION = 'association';

    public const MEMBERSHIP = 'membership';

    public const BOARD = 'board';

    public const MEETINGS = 'meetings';

    public const MINUTES = 'minutes';

    public const PRIVACY = 'privacy';

    public const COMPLETE = 'complete';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::WELCOME,
            self::ASSOCIATION,
            self::MEMBERSHIP,
            self::BOARD,
            self::MEETINGS,
            self::MINUTES,
            self::PRIVACY,
            self::COMPLETE,
        ];
    }

    public static function isValid(string $step): bool
    {
        return in_array($step, self::all(), true);
    }

    public static function normalize(?string $step): string
    {
        if ($step === null || $step === '' || ! self::isValid($step)) {
            return self::WELCOME;
        }

        return $step;
    }

    public static function index(string $step): int
    {
        $index = array_search(self::normalize($step), self::all(), true);

        return $index === false ? 0 : $index;
    }

    public static function next(string $step): string
    {
        $all = self::all();
        $index = self::index($step);

        if ($index >= count($all) - 1) {
            return self::COMPLETE;
        }

        return $all[$index + 1];
    }

    public static function previous(string $step): string
    {
        $all = self::all();
        $index = self::index($step);

        if ($index <= 0) {
            return self::WELCOME;
        }

        return $all[$index - 1];
    }
}
