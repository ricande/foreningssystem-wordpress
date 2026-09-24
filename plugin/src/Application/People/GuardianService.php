<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use DateTimeImmutable;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Guardian\GuardianApproval;
use Foreningssystem\Domain\Guardian\GuardianRelationship;
use Foreningssystem\Domain\Guardian\GuardianRepository;
use Foreningssystem\Domain\Meeting\AuditLog;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\PersonRepository;
use InvalidArgumentException;

final class GuardianService
{
    public function __construct(
        private readonly GuardianRepository $guardians,
        private readonly PersonRepository $people,
        private readonly Authorizer $authorizer,
        private readonly AuditLog $audit,
    ) {
    }

    public function relate(
        int $childPersonId,
        int $guardianPersonId,
        string $relationship,
        ?AssociationDate $startedOn,
    ): int {
        $this->require(Capabilities::EDIT_MEMBERS);
        $this->requirePerson($childPersonId);
        $this->requirePerson($guardianPersonId);
        $saved = $this->guardians->addRelationship(new GuardianRelationship(
            null,
            $childPersonId,
            $guardianPersonId,
            trim($relationship),
            $startedOn,
            null
        ));

        return (int) $saved->id();
    }

    public function approve(
        int $childPersonId,
        int $guardianPersonId,
        string $purpose,
        string $basisNote,
        DateTimeImmutable $approvedAt,
        string $method,
        string $noticeVersion,
        string $note,
        int $actorUserId,
    ): int {
        $this->require(Capabilities::EDIT_MEMBERS);
        $this->requirePerson($childPersonId);
        $this->requirePerson($guardianPersonId);

        if ($this->relationship($childPersonId, $guardianPersonId) === null) {
            throw new InvalidArgumentException('Guardian approval needs an explicit guardian relationship.');
        }

        $saved = $this->guardians->addApproval(new GuardianApproval(
            null,
            $childPersonId,
            $guardianPersonId,
            trim($purpose),
            trim($basisNote),
            $approvedAt,
            trim($method),
            trim($noticeVersion),
            $actorUserId > 0 ? $actorUserId : null,
            null,
            trim($note)
        ));
        $id = (int) $saved->id();
        $this->audit->record('guardian_approval', $id, 'guardian_approval_recorded', $actorUserId);

        return $id;
    }

    public function withdraw(int $approvalId, DateTimeImmutable $at, int $actorUserId): void
    {
        $this->require(Capabilities::EDIT_MEMBERS);
        $approval = $this->guardians->findApproval($approvalId);

        if (! $approval instanceof GuardianApproval) {
            throw new \RuntimeException('Guardian approval was not found.');
        }

        $this->guardians->saveApproval($approval->withdrawn($at));
        $this->audit->record('guardian_approval', $approvalId, 'guardian_approval_withdrawn', $actorUserId);
    }

    private function relationship(int $childPersonId, int $guardianPersonId): ?GuardianRelationship
    {
        foreach ($this->guardians->relationshipsForChild($childPersonId) as $relationship) {
            if ($relationship->guardianPersonId() === $guardianPersonId && ! $relationship->endedOn() instanceof AssociationDate) {
                return $relationship;
            }
        }

        return null;
    }

    private function requirePerson(int $personId): void
    {
        if ($this->people->find($personId) === null) {
            throw new \RuntimeException('Person was not found.');
        }
    }

    private function require(string $capability): void
    {
        if (! $this->authorizer->allows($capability)) {
            throw new NotAllowed($capability);
        }
    }
}
