<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use Foreningssystem\Domain\Membership\Membership;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Person\Person;

final class PersonRecord
{
    public function __construct(
        private readonly Person $person,
        private readonly ?MembershipPeriod $membership,
        private readonly ?Membership $account = null,
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

    public function account(): ?Membership
    {
        return $this->account;
    }

    public function membershipNumber(): string
    {
        return $this->account instanceof Membership ? $this->account->number() : '';
    }
}
