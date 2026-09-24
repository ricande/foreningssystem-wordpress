<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use DateTimeImmutable;
use Foreningssystem\Domain\Guardian\GuardianApproval;
use Foreningssystem\Domain\Guardian\GuardianRelationship;
use Foreningssystem\Domain\Guardian\GuardianRepository;
use Foreningssystem\Domain\Membership\AssociationDate;

final class WpdbGuardianRepository implements GuardianRepository
{
    public function addRelationship(GuardianRelationship $relationship): GuardianRelationship
    {
        global $wpdb;

        $inserted = $wpdb->insert($this->relationships(), [
            'child_person_id' => $relationship->childPersonId(),
            'guardian_person_id' => $relationship->guardianPersonId(),
            'relationship_label' => $relationship->relationship(),
            'started_on' => $relationship->startedOn()?->iso(),
            'ended_on' => $relationship->endedOn()?->iso(),
        ], ['%d', '%d', '%s', '%s', '%s']);

        if ($inserted === false) {
            throw new \RuntimeException('The guardian relationship could not be saved.');
        }

        return $relationship->withId((int) $wpdb->insert_id);
    }

    public function saveRelationship(GuardianRelationship $relationship): void
    {
        global $wpdb;

        $id = $relationship->id();

        if ($id === null) {
            throw new \RuntimeException('Guardian relationship was not saved.');
        }

        $updated = $wpdb->update(
            $this->relationships(),
            ['ended_on' => $relationship->endedOn()?->iso()],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('The guardian relationship could not be saved.');
        }
    }

    public function relationshipsForChild(int $childPersonId): array
    {
        return $this->relationshipsWhere('child_person_id', $childPersonId);
    }

    public function relationshipsForGuardian(int $guardianPersonId): array
    {
        return $this->relationshipsWhere('guardian_person_id', $guardianPersonId);
    }

    public function addApproval(GuardianApproval $approval): GuardianApproval
    {
        global $wpdb;

        $inserted = $wpdb->insert($this->approvals(), [
            'child_person_id' => $approval->childPersonId(),
            'guardian_person_id' => $approval->guardianPersonId(),
            'purpose' => $approval->purpose(),
            'basis_note' => $approval->basisNote(),
            'approved_at' => $approval->approvedAt()->format('Y-m-d H:i:s'),
            'method' => $approval->method(),
            'notice_version' => $approval->noticeVersion(),
            'recorded_by_user_id' => $approval->recordedByUserId(),
            'withdrawn_at' => $approval->withdrawnAt()?->format('Y-m-d H:i:s'),
            'note' => $approval->note(),
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);

        if ($inserted === false) {
            throw new \RuntimeException('The guardian approval could not be saved.');
        }

        return $approval->withId((int) $wpdb->insert_id);
    }

    public function saveApproval(GuardianApproval $approval): void
    {
        global $wpdb;

        $id = $approval->id();

        if ($id === null) {
            throw new \RuntimeException('Guardian approval was not saved.');
        }

        $updated = $wpdb->update(
            $this->approvals(),
            ['withdrawn_at' => $approval->withdrawnAt()?->format('Y-m-d H:i:s')],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('The guardian approval could not be saved.');
        }
    }

    public function approvalsForChild(int $childPersonId): array
    {
        return $this->approvalsWhere('child_person_id', $childPersonId);
    }

    public function approvalsForGuardian(int $guardianPersonId): array
    {
        return $this->approvalsWhere('guardian_person_id', $guardianPersonId);
    }

    public function findApproval(int $id): ?GuardianApproval
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->approvals() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->mapApproval($row) : null;
    }

    /**
     * @return list<GuardianRelationship>
     */
    private function relationshipsWhere(string $column, int $personId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->relationships() . " WHERE {$column} = %d ORDER BY id",
            $personId
        ), ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_map($this->mapRelationship(...), $rows);
    }

    /**
     * @return list<GuardianApproval>
     */
    private function approvalsWhere(string $column, int $personId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->approvals() . " WHERE {$column} = %d ORDER BY id",
            $personId
        ), ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_map($this->mapApproval(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRelationship(array $row): GuardianRelationship
    {
        return new GuardianRelationship(
            (int) $row['id'],
            (int) $row['child_person_id'],
            (int) $row['guardian_person_id'],
            (string) $row['relationship_label'],
            $this->date($row['started_on'] ?? null),
            $this->date($row['ended_on'] ?? null)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapApproval(array $row): GuardianApproval
    {
        $actor = $row['recorded_by_user_id'];
        $withdrawn = $row['withdrawn_at'] ?? null;

        return new GuardianApproval(
            (int) $row['id'],
            (int) $row['child_person_id'],
            (int) $row['guardian_person_id'],
            (string) $row['purpose'],
            (string) $row['basis_note'],
            new DateTimeImmutable((string) $row['approved_at']),
            (string) $row['method'],
            (string) $row['notice_version'],
            is_numeric($actor) && (int) $actor > 0 ? (int) $actor : null,
            is_string($withdrawn) && $withdrawn !== '' ? new DateTimeImmutable($withdrawn) : null,
            (string) $row['note']
        );
    }

    private function date(mixed $value): ?AssociationDate
    {
        if (! is_string($value) || $value === '' || $value === '0000-00-00') {
            return null;
        }

        return AssociationDate::fromIso($value);
    }

    private function relationships(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_guardian_relationship';
    }

    private function approvals(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_guardian_approval';
    }
}
