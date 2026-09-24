<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Identity\PersonalIdentityNumber;
use Foreningssystem\Domain\Identity\PersonalIdentityRecord;
use Foreningssystem\Domain\Identity\PersonalIdentityRepository;
use Foreningssystem\Domain\Membership\AssociationDate;

final class WpdbPersonalIdentityRepository implements PersonalIdentityRepository
{
    public function findForPerson(int $personId): ?PersonalIdentityRecord
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE person_id = %d', $personId), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function save(PersonalIdentityRecord $record): PersonalIdentityRecord
    {
        global $wpdb;

        $data = [
            'person_id' => $record->personId(),
            'identifier' => $record->number()->canonical(),
            'identifier_type' => 'swedish_personal_identity_number',
            'purpose' => $record->purpose(),
            'basis_note' => $record->basisNote(),
            'collected_on' => $record->collectedOn()->iso(),
            'recorded_by_user_id' => $record->recordedByUserId(),
        ];

        if ($record->id() === null) {
            $inserted = $wpdb->insert($this->table(), $data, ['%d', '%s', '%s', '%s', '%s', '%s', '%d']);

            if ($inserted === false) {
                throw new \RuntimeException('The personal identity record could not be saved.');
            }

            return $record->withId((int) $wpdb->insert_id);
        }

        $updated = $wpdb->update($this->table(), $data, ['id' => $record->id()], ['%d', '%s', '%s', '%s', '%s', '%s', '%d'], ['%d']);

        if ($updated === false) {
            throw new \RuntimeException('The personal identity record could not be saved.');
        }

        return $record;
    }

    public function remove(int $personId): void
    {
        global $wpdb;

        $deleted = $wpdb->delete($this->table(), ['person_id' => $personId], ['%d']);

        if ($deleted === false) {
            throw new \RuntimeException('The personal identity record could not be removed.');
        }
    }

    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_personal_identity';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): PersonalIdentityRecord
    {
        $actor = $row['recorded_by_user_id'];

        return new PersonalIdentityRecord(
            (int) $row['id'],
            (int) $row['person_id'],
            PersonalIdentityNumber::parse((string) $row['identifier'], AssociationDate::fromIso(wp_date('Y-m-d'))),
            (string) $row['purpose'],
            (string) $row['basis_note'],
            AssociationDate::fromIso((string) $row['collected_on']),
            is_numeric($actor) && (int) $actor > 0 ? (int) $actor : null
        );
    }
}
