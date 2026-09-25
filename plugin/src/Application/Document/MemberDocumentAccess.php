<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Document;

use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MemberCoverage;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Person\PersonRepository;

final class MemberDocumentAccess
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly MembershipRepository $memberships,
        private readonly WordPressIdentity $identities,
    ) {
    }

    public function allows(int $wordpressUserId, AssociationDate $on): bool
    {
        if ($wordpressUserId < 1 || ! $this->identities->exists($wordpressUserId)) {
            return false;
        }

        foreach ($this->people->all() as $person) {
            $personId = $person->id();

            if ($person->wordpressUserId() !== $wordpressUserId || $personId === null) {
                continue;
            }

            $participants = $this->memberships->allParticipants();
            $periods = $this->memberships->all();

            if (MemberCoverage::isActiveMember($personId, $on, $participants, $periods)) {
                return true;
            }
        }

        return false;
    }
}