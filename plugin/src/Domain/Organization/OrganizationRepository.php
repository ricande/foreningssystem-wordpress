<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Organization;

interface OrganizationRepository
{
    public function add(Organization $organization): Organization;

    public function find(int $id): ?Organization;

    public function findByNumber(string $canonical): ?Organization;

    /**
     * @return list<Organization>
     */
    public function all(): array;
}
