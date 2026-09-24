<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\Membership;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipParticipant;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Domain\Organization\Organization;
use Foreningssystem\Domain\Organization\OrganizationNumber;
use Foreningssystem\Domain\Organization\OrganizationRepository;
use Foreningssystem\Domain\Person\PersonRepository;

final class CompanyMemberships
{
    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly MembershipRepository $memberships,
        private readonly PersonRepository $people,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
    ) {
    }

    public function register(
        string $name,
        string $organizationNumber,
        string $email,
        string $postalAddress,
        string $membershipNumber,
        AssociationDate $startedOn,
        ?int $contactPersonId,
    ): int {
        $this->require(Capabilities::EDIT_MEMBERS);

        return $this->transaction->run(function () use ($name, $organizationNumber, $email, $postalAddress, $membershipNumber, $startedOn, $contactPersonId): int {
            $number = trim($membershipNumber);

            if ($this->memberships->findMembershipByNumber($number) instanceof Membership) {
                throw new MembershipRuleException('Membership number is already used.');
            }

            $parsed = trim($organizationNumber) === '' ? null : OrganizationNumber::parse($organizationNumber);

            if ($parsed instanceof OrganizationNumber && $this->organizations->findByNumber($parsed->canonical()) instanceof Organization) {
                throw new MembershipRuleException('The organization number is already used.');
            }

            $organization = $this->organizations->add(new Organization(null, trim($name), $parsed, trim($email), trim($postalAddress)));
            $organizationId = $organization->id();

            if ($organizationId === null) {
                throw new \RuntimeException('The organization was not saved.');
            }

            $membership = $this->memberships->addMembership(new Membership(null, $number, MembershipKind::Company, $organizationId));
            $membershipId = $membership->id();

            if ($membershipId === null) {
                throw new \RuntimeException('The membership was not saved.');
            }

            if ($contactPersonId !== null && $contactPersonId > 0) {
                if ($this->people->find($contactPersonId) === null) {
                    throw new \RuntimeException('Person was not found.');
                }

                $this->memberships->addParticipant(new MembershipParticipant(
                    null,
                    $membershipId,
                    $contactPersonId,
                    ParticipantRole::Contact,
                    true,
                    $startedOn,
                    null
                ));
            }

            $this->memberships->add(new MembershipPeriod(
                null,
                $membershipId,
                MembershipStatus::Active,
                $startedOn,
                null,
                MembershipKind::Company->value
            ));

            return $membershipId;
        });
    }

    private function require(string $capability): void
    {
        if (! $this->authorizer->allows($capability)) {
            throw new NotAllowed($capability);
        }
    }
}
