<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Account;

enum AccountOutcome: string
{
    case Created = 'created';
    case Eligible = 'eligible';
    case AlreadyLinked = 'already_linked';
    case NotActiveMember = 'not_active_member';
    case NoEmail = 'no_email';
    case KnownMinor = 'known_minor';
    case Deceased = 'deceased';
    case SharedPersonEmail = 'shared_person_email';
    case WordpressEmailConflict = 'wordpress_email_conflict';
    case Failed = 'failed';
    case Linked = 'linked';
    case Unlinked = 'unlinked';
    case UserTaken = 'user_taken';
    case UnlinkFirst = 'unlink_first';
    case MinorLinkRefused = 'minor_link_refused';
    case UserNotFound = 'user_not_found';
}
