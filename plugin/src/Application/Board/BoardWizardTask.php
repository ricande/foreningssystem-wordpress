<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Board;

/**
 * One board change at a time. Domain mutations stay in BoardService.
 */
final class BoardWizardTask
{
    public const REPLACE = 'replace';

    public const ADD = 'add';

    public const END = 'end';

    public const CANCEL = 'cancel';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::REPLACE,
            self::ADD,
            self::END,
            self::CANCEL,
        ];
    }

    public static function isValid(string $task): bool
    {
        return in_array($task, self::all(), true);
    }

    public static function normalize(?string $task): ?string
    {
        if ($task === null || $task === '' || ! self::isValid($task)) {
            return null;
        }

        return $task;
    }

    public static function needsRole(string $task): bool
    {
        return in_array($task, [self::REPLACE, self::ADD, self::END, self::CANCEL], true);
    }

    public static function needsAssignment(string $task): bool
    {
        return in_array($task, [self::END, self::CANCEL], true);
    }

    public static function needsPersonDates(string $task): bool
    {
        return in_array($task, [self::REPLACE, self::ADD], true);
    }
}
