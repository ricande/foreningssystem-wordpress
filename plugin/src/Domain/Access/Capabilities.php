<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Access;

final class Capabilities
{
    public const MANAGE_ASSOCIATION = 'manage_association';

    public const VIEW_MEMBERS = 'view_members';

    public const EDIT_MEMBERS = 'edit_members';

    public const EXPORT_MEMBERS = 'export_members';

    public const ERASE_MEMBER_DATA = 'erase_member_data';

    public const MANAGE_BOARD = 'manage_board';

    public const VIEW_INTERNAL_MEETINGS = 'view_internal_meetings';

    public const MANAGE_MEETINGS = 'manage_meetings';

    public const RECORD_MEETING = 'record_meeting';

    public const FINALIZE_MINUTES = 'finalize_minutes';

    public const PUBLISH_MINUTES = 'publish_minutes';

    public const MANAGE_DOCUMENTS = 'manage_documents';

    public const VIEW_BOARD_DOCUMENTS = 'view_board_documents';

    public const MANAGE_FEES = 'manage_fees';

    public const SEND_MEMBER_MAIL = 'send_member_mail';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::MANAGE_ASSOCIATION,
            self::VIEW_MEMBERS,
            self::EDIT_MEMBERS,
            self::EXPORT_MEMBERS,
            self::ERASE_MEMBER_DATA,
            self::MANAGE_BOARD,
            self::VIEW_INTERNAL_MEETINGS,
            self::MANAGE_MEETINGS,
            self::RECORD_MEETING,
            self::FINALIZE_MINUTES,
            self::PUBLISH_MINUTES,
            self::MANAGE_DOCUMENTS,
            self::VIEW_BOARD_DOCUMENTS,
            self::MANAGE_FEES,
            self::SEND_MEMBER_MAIL,
        ];
    }
}
