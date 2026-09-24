<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

interface MembershipRepository
{
    public function addMembership(Membership $membership): Membership;

    public function findMembership(int $id): ?Membership;

    public function findMembershipByNumber(string $number): ?Membership;

    /**
     * @return list<Membership>
     */
    public function allMemberships(): array;

    public function addParticipant(MembershipParticipant $participant): MembershipParticipant;

    public function saveParticipant(MembershipParticipant $participant): void;

    /**
     * @return list<MembershipParticipant>
     */
    public function participantsForMembership(int $membershipId): array;

    /**
     * @return list<MembershipParticipant>
     */
    public function allParticipants(): array;

    public function add(MembershipPeriod $period): MembershipPeriod;

    public function save(MembershipPeriod $period): void;

    public function find(int $id): ?MembershipPeriod;

    /**
     * @return list<MembershipPeriod>
     */
    public function periodsForMembership(int $membershipId): array;

    /**
     * @return list<MembershipPeriod>
     */
    public function all(): array;
}
