<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Access;

use InvalidArgumentException;

final class RoleBundles
{
    public const SECRETARY = 'assoc_secretary';

    public const CHAIR = 'assoc_chair';

    public const TREASURER = 'assoc_treasurer';

    public const BOARD_MEMBER = 'assoc_board_member';

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [
            self::SECRETARY,
            self::CHAIR,
            self::TREASURER,
            self::BOARD_MEMBER,
        ];
    }

    public static function auditId(string $role): int
    {
        return match ($role) {
            self::SECRETARY => 1,
            self::CHAIR => 2,
            self::TREASURER => 3,
            self::BOARD_MEMBER => 4,
            default => throw new InvalidArgumentException('Unknown association role.'),
        };
    }

    /**
     * Suggested starting bundles. Who may finalize minutes is a setting, so the chair
     * grant is only the initial value.
     *
     * @return array<string, list<string>>
     */
    public static function defaults(): array
    {
        $secretary = [
            Capabilities::ACCESS_ASSOCIATION,
            Capabilities::VIEW_INTERNAL_MEETINGS,
            Capabilities::MANAGE_MEETINGS,
            Capabilities::RECORD_MEETING,
            Capabilities::MANAGE_DOCUMENTS,
            Capabilities::VIEW_BOARD_DOCUMENTS,
            Capabilities::VIEW_MEMBERS,
        ];

        return [
            self::SECRETARY => $secretary,
            self::CHAIR => array_merge($secretary, [
                Capabilities::MANAGE_BOARD,
                Capabilities::FINALIZE_MINUTES,
                Capabilities::PUBLISH_MINUTES,
            ]),
            self::TREASURER => [
                Capabilities::ACCESS_ASSOCIATION,
                Capabilities::VIEW_MEMBERS,
                Capabilities::EXPORT_MEMBERS,
            ],
            self::BOARD_MEMBER => [
                Capabilities::ACCESS_ASSOCIATION,
                Capabilities::VIEW_INTERNAL_MEETINGS,
                Capabilities::VIEW_BOARD_DOCUMENTS,
                Capabilities::VIEW_MEMBERS,
            ],
        ];
    }
}
