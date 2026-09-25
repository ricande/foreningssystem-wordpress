<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Board;

/**
 * Guided board admin wizard steps. Navigation is request-scoped (query args), not setup options.
 */
final class BoardWizardStep
{
    public const OVERVIEW = 'overview';

    public const TASK = 'task';

    public const ROLE = 'role';

    public const PERSON = 'person';

    public const CONFIRM = 'confirm';

    public const DONE = 'done';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::OVERVIEW,
            self::TASK,
            self::ROLE,
            self::PERSON,
            self::CONFIRM,
            self::DONE,
        ];
    }

    public static function isValid(string $step): bool
    {
        return in_array($step, self::all(), true);
    }

    public static function normalize(?string $step): string
    {
        if ($step === null || $step === '' || ! self::isValid($step)) {
            return self::OVERVIEW;
        }

        return $step;
    }

    public static function index(string $step): int
    {
        $index = array_search(self::normalize($step), self::all(), true);

        return $index === false ? 0 : $index;
    }
}
