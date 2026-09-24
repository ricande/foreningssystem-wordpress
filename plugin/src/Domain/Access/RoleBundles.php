<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Access;

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

    /**
     * Suggested starting bundles. Who may finalize minutes is a setting, so the chair
     * grant is only the initial value.
     *
     * @return array<string, list<string>>
     */
    public static function defaults(): array
    {
        $secretary = [
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
                Capabilities::VIEW_MEMBERS,
                Capabilities::EXPORT_MEMBERS,
            ],
            self::BOARD_MEMBER => [
                Capabilities::VIEW_INTERNAL_MEETINGS,
                Capabilities::VIEW_BOARD_DOCUMENTS,
                Capabilities::VIEW_MEMBERS,
            ],
        ];
    }
}
