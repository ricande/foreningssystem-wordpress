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

        $person = $this->people->find($personId);

        if ($person === null) {
            throw new \RuntimeException('Person was not found.');
        }

        $number = PersonalIdentityNumber::parse($rawNumber, $today);
        $birthDate = $person->birthDate();

        if ($birthDate instanceof AssociationDate && $birthDate->iso() !== $number->civilBirthDate()->iso()) {
            throw new \InvalidArgumentException('The personal identity number does not match the birth date.');
        }

        $existing = $this->identities->findForPerson($personId);
        $record = new PersonalIdentityRecord(
            $existing?->id(),
            $personId,
            $number,
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

    public function isRecorded(int $personId): bool
    {
        return $this->identities->findForPerson($personId) instanceof PersonalIdentityRecord;
    }

    public function conflictsWithBirthDate(int $personId, AssociationDate $birthDate): bool
    {
        $record = $this->identities->findForPerson($personId);

        return $record instanceof PersonalIdentityRecord && $record->number()->civilBirthDate()->iso() !== $birthDate->iso();
    }

    /**
     * @return array{number: string, purpose: string, basis_note: string, collected_on: string}|null
     */
    public function authorizedRecord(int $personId): ?array
    {
        $this->require(Capabilities::VIEW_PERSONAL_IDENTITY_NUMBERS);
        $record = $this->identities->findForPerson($personId);

        if (! $record instanceof PersonalIdentityRecord) {
            return null;
        }

        return [
            'number' => $record->number()->canonical(),
            'purpose' => $record->purpose(),
            'basis_note' => $record->basisNote(),
            'collected_on' => $record->collectedOn()->iso(),
        ];
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
