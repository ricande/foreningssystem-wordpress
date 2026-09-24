<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\Membership;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipParticipant;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Membership\ParticipantRole;

final class WpdbMembershipRepository implements MembershipRepository
{
    public function addMembership(Membership $membership): Membership
    {
        global $wpdb;

        $data = [
            'membership_number' => $membership->number(),
            'kind' => $membership->kind()->value,
        ];
        $format = ['%s', '%s'];

        if ($membership->organizationId() !== null) {
            $data['organization_id'] = $membership->organizationId();
            $format[] = '%d';
        }

        $inserted = $wpdb->insert($this->memberships(), $data, $format);

        if ($inserted === false) {
            if (str_contains(strtolower($wpdb->last_error), 'duplicate')) {
                throw new MembershipRuleException('Membership number is already used.');
            }

            throw new \RuntimeException('The membership could not be saved.');
        }

        return $membership->withId((int) $wpdb->insert_id);
    }

    public function findMembership(int $id): ?Membership
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->memberships() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->mapMembership($row) : null;
    }

    public function findMembershipByNumber(string $number): ?Membership
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->memberships() . ' WHERE membership_number = %s', $number), ARRAY_A);

        return is_array($row) ? $this->mapMembership($row) : null;
    }

    public function allMemberships(): array
    {
        global $wpdb;

        return $this->mapRows($wpdb->get_results('SELECT * FROM ' . $this->memberships() . ' ORDER BY id', ARRAY_A), $this->mapMembership(...));
    }

    public function addParticipant(MembershipParticipant $participant): MembershipParticipant
    {
        global $wpdb;

        $data = [
            'membership_id' => $participant->membershipId(),
            'person_id' => $participant->personId(),
            'role' => $participant->role()->value,
            'is_primary' => $participant->isPrimary() ? 1 : 0,
        ];
        $format = ['%d', '%d', '%s', '%d'];

        if ($participant->endedOn() !== null) {
            $data['ended_on'] = $participant->endedOn()->iso();
            $format[] = '%s';
        }

        $inserted = $wpdb->insert($this->participants(), $data, $format);

        if ($inserted === false) {
            throw new \RuntimeException('The membership participant could not be saved.');
        }

        return $participant->withId((int) $wpdb->insert_id);
    }

    public function saveParticipant(MembershipParticipant $participant): void
    {
        global $wpdb;

        $id = $participant->id();

        if ($id === null) {
            throw new \RuntimeException('Membership participant was not saved.');
        }

        $updated = $wpdb->update(
            $this->participants(),
            [
                'role' => $participant->role()->value,
                'is_primary' => $participant->isPrimary() ? 1 : 0,
                'ended_on' => $participant->endedOn()?->iso(),
            ],
            ['id' => $id],
            ['%s', '%d', '%s'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('The membership participant could not be saved.');
        }
    }

    public function participantsForMembership(int $membershipId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->participants() . ' WHERE membership_id = %d ORDER BY id',
            $membershipId
        ), ARRAY_A);

        return $this->mapRows($rows, $this->mapParticipant(...));
    }

    public function allParticipants(): array
    {
        global $wpdb;

        return $this->mapRows($wpdb->get_results('SELECT * FROM ' . $this->participants() . ' ORDER BY id', ARRAY_A), $this->mapParticipant(...));
    }

    public function add(MembershipPeriod $period): MembershipPeriod
    {
        global $wpdb;

        $data = [
            'membership_id' => $period->membershipId(),
            'status' => $period->status()->value,
            'started_on' => $period->startedOn()->iso(),
            'historical_class' => $period->historicalClass(),
        ];
        $format = ['%d', '%s', '%s', '%s'];

        if ($period->endedOn() !== null) {
            $data['ended_on'] = $period->endedOn()->iso();
            $format[] = '%s';
        }

        $inserted = $wpdb->insert($this->periods(), $data, $format);

        if ($inserted === false) {
            throw new \RuntimeException('The membership period could not be saved.');
        }

        return $period->withId((int) $wpdb->insert_id);
    }

    public function save(MembershipPeriod $period): void
    {
        global $wpdb;

        $id = $period->id();

        if ($id === null) {
            throw new \RuntimeException('Membership was not saved.');
        }

        $updated = $wpdb->update(
            $this->periods(),
            [
                'status' => $period->status()->value,
                'started_on' => $period->startedOn()->iso(),
                'ended_on' => $period->endedOn()?->iso(),
                'historical_class' => $period->historicalClass(),
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('The membership could not be saved.');
        }
    }

    public function find(int $id): ?MembershipPeriod
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->periods() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->mapPeriod($row) : null;
    }

    public function periodsForMembership(int $membershipId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->periods() . ' WHERE membership_id = %d ORDER BY started_on, id',
            $membershipId
        ), ARRAY_A);

        return $this->mapRows($rows, $this->mapPeriod(...));
    }

    public function all(): array
    {
        global $wpdb;

        return $this->mapRows($wpdb->get_results('SELECT * FROM ' . $this->periods() . ' ORDER BY id', ARRAY_A), $this->mapPeriod(...));
    }

    private function memberships(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_membership';
    }

    private function periods(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_membership_period';
    }

    private function participants(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_membership_participant';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapMembership(array $row): Membership
    {
        $organizationId = $row['organization_id'];

        return new Membership(
            (int) $row['id'],
            (string) $row['membership_number'],
            MembershipKind::fromSlug((string) $row['kind']),
            is_numeric($organizationId) && (int) $organizationId > 0 ? (int) $organizationId : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapParticipant(array $row): MembershipParticipant
    {
        return new MembershipParticipant(
            (int) $row['id'],
            (int) $row['membership_id'],
            (int) $row['person_id'],
            ParticipantRole::from((string) $row['role']),
            (int) $row['is_primary'] === 1,
            $this->date($row['ended_on'] ?? null)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapPeriod(array $row): MembershipPeriod
    {
        return new MembershipPeriod(
            (int) $row['id'],
            (int) $row['membership_id'],
            MembershipStatus::from((string) $row['status']),
            AssociationDate::fromIso((string) $row['started_on']),
            $this->date($row['ended_on'] ?? null),
            (string) ($row['historical_class'] ?? '')
        );
    }

    private function date(mixed $value): ?AssociationDate
    {
        if (! is_string($value) || $value === '' || $value === '0000-00-00') {
            return null;
        }

        return AssociationDate::fromIso($value);
    }

    /**
     * @param mixed $rows
     * @param callable(array<string, mixed>): mixed $map
     * @return list<mixed>
     */
    private function mapRows(mixed $rows, callable $map): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_map($map, $rows);
    }
}
