<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Membership\MembershipStatus;

final class WpdbMembershipRepository implements MembershipRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_membership';
    }

    public function add(MembershipPeriod $period): MembershipPeriod
    {
        global $wpdb;

        $data = [
            'person_id' => $period->personId(),
            'membership_number' => $period->number(),
            'membership_type' => $period->type(),
            'status' => $period->status()->value,
            'started_on' => $period->startedOn()->iso(),
        ];
        $format = ['%d', '%s', '%s', '%s', '%s'];

        if ($period->endedOn() !== null) {
            $data['ended_on'] = $period->endedOn()->iso();
            $format[] = '%s';
        }

        $inserted = $wpdb->insert($this->table(), $data, $format);

        if ($inserted === false) {
            throw new \RuntimeException('The membership could not be saved.');
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

        $data = [
            'person_id' => $period->personId(),
            'membership_number' => $period->number(),
            'membership_type' => $period->type(),
            'status' => $period->status()->value,
            'started_on' => $period->startedOn()->iso(),
            'ended_on' => $period->endedOn()?->iso(),
        ];
        $updated = $wpdb->update(
            $this->table(),
            $data,
            ['id' => $id],
            ['%d', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            throw new \RuntimeException('The membership could not be saved.');
        }
    }

    public function find(int $id): ?MembershipPeriod
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SELECT * FROM ' . $this->table() . ' ORDER BY started_on, id', ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_map($this->map(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): MembershipPeriod
    {
        $endedOn = $row['ended_on'];

        return new MembershipPeriod(
            (int) $row['id'],
            (int) $row['person_id'],
            (string) $row['membership_number'],
            (string) $row['membership_type'],
            MembershipStatus::from((string) $row['status']),
            AssociationDate::fromIso((string) $row['started_on']),
            is_string($endedOn) && $endedOn !== '' && $endedOn !== '0000-00-00'
                ? AssociationDate::fromIso($endedOn)
                : null
        );
    }
}
