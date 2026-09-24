<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Person\Person;

final class PersonRecord
{
    public function __construct(
        private readonly Person $person,
        private readonly ?MembershipPeriod $membership,
    ) {
    }

    public function person(): Person
    {
        return $this->person;
    }

    public function membership(): ?MembershipPeriod
    {
        return $this->membership;
    }
}
