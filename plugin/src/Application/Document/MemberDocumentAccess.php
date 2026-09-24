<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Document;

use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Person\PersonRepository;

final class MemberDocumentAccess
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly MembershipRepository $memberships,
    ) {
    }

    public function allows(int $wordpressUserId): bool
    {
        if ($wordpressUserId < 1) {
            return false;
        }

        foreach ($this->people->all() as $person) {
            $personId = $person->id();

            if ($person->wordpressUserId() !== $wordpressUserId || $personId === null) {
                continue;
            }

            foreach ($this->memberships->all() as $period) {
                if ($period->personId() === $personId && $period->countsAsActiveMember()) {
                    return true;
                }
            }
        }

        return false;
    }
}