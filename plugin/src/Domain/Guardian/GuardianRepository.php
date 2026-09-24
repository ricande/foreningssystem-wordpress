<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Guardian;

interface GuardianRepository
{
    public function addRelationship(GuardianRelationship $relationship): GuardianRelationship;

    public function saveRelationship(GuardianRelationship $relationship): void;

    /**
     * @return list<GuardianRelationship>
     */
    public function relationshipsForChild(int $childPersonId): array;

    /**
     * @return list<GuardianRelationship>
     */
    public function relationshipsForGuardian(int $guardianPersonId): array;

    public function addApproval(GuardianApproval $approval): GuardianApproval;

    public function saveApproval(GuardianApproval $approval): void;

    /**
     * @return list<GuardianApproval>
     */
    public function approvalsForChild(int $childPersonId): array;

    /**
     * @return list<GuardianApproval>
     */
    public function approvalsForGuardian(int $guardianPersonId): array;

    public function findApproval(int $id): ?GuardianApproval;
}
