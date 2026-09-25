<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\MemberArea\MemberArea;
use Foreningssystem\Application\MemberArea\MemberAreaSnapshot;
use Foreningssystem\Domain\Membership\AssociationDate;

final class WordpressMemberArea
{
    public static function open(int $wordpressUserId): MemberAreaSnapshot
    {
        return (new MemberArea(
            new WpdbPersonRepository(),
            new WpdbMembershipRepository(),
            new WpdbPersonalIdentityRepository(),
            new WpWordPressIdentity()
        ))->open($wordpressUserId, AssociationDate::fromIso(wp_date('Y-m-d')));
    }
}
