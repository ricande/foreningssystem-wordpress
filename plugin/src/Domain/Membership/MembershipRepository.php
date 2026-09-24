<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Membership;

interface MembershipRepository
{
    public function add(MembershipPeriod $period): MembershipPeriod;

    public function save(MembershipPeriod $period): void;

    public function find(int $id): ?MembershipPeriod;

    public function findByNumber(string $number): ?MembershipPeriod;

    /**
     * @return list<MembershipPeriod>
     */
    public function forPerson(int $personId): array;

    /**
     * @return list<MembershipPeriod>
     */
    public function all(): array;
}
