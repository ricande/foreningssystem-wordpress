<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Identity\PersonalIdentityNumber;
use Foreningssystem\Domain\Identity\PersonalIdentityRecord;
use Foreningssystem\Domain\Identity\PersonalIdentityRepository;
use Foreningssystem\Domain\Meeting\AuditLog;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\PersonRepository;

final class PersonalIdentityService
{
    public function __construct(
        private readonly PersonalIdentityRepository $identities,
        private readonly PersonRepository $people,
        private readonly Authorizer $authorizer,
        private readonly AuditLog $audit,
    ) {
    }

    public function store(
        int $personId,
        string $rawNumber,
        string $purpose,
        string $basisNote,
        AssociationDate $collectedOn,
        AssociationDate $today,
        int $actorUserId,
    ): void {
        $this->require(Capabilities::EDIT_PERSONAL_IDENTITY_NUMBERS);

        if ($this->people->find($personId) === null) {
            throw new \RuntimeException('Person was not found.');
        }

        $existing = $this->identities->findForPerson($personId);
        $record = new PersonalIdentityRecord(
            $existing?->id(),
            $personId,
            PersonalIdentityNumber::parse($rawNumber, $today),
            trim($purpose),
            trim($basisNote),
            $collectedOn,
            $actorUserId > 0 ? $actorUserId : null
        );
        $saved = $this->identities->save($record);
        $this->audit->record(
            'personal_identity',
            (int) $saved->id(),
            $existing instanceof PersonalIdentityRecord ? 'identity_changed' : 'identity_added',
            $actorUserId
        );
    }

    public function remove(int $personId, int $actorUserId): void
    {
        $this->require(Capabilities::EDIT_PERSONAL_IDENTITY_NUMBERS);
        $existing = $this->identities->findForPerson($personId);

        if (! $existing instanceof PersonalIdentityRecord || $existing->id() === null) {
            return;
        }

        $this->identities->remove($personId);
        $this->audit->record('personal_identity', $existing->id(), 'identity_removed', $actorUserId);
    }

    public function reveal(int $personId): ?string
    {
        $this->require(Capabilities::VIEW_PERSONAL_IDENTITY_NUMBERS);
        $record = $this->identities->findForPerson($personId);

        return $record instanceof PersonalIdentityRecord ? $record->number()->canonical() : null;
    }

    public function masked(int $personId): ?string
    {
        if (! $this->authorizer->allows(Capabilities::VIEW_PERSONAL_IDENTITY_NUMBERS)) {
            return null;
        }

        $record = $this->identities->findForPerson($personId);

        return $record instanceof PersonalIdentityRecord ? $record->number()->masked() : null;
    }

    private function require(string $capability): void
    {
        if (! $this->authorizer->allows($capability)) {
            throw new NotAllowed($capability);
        }
    }
}
