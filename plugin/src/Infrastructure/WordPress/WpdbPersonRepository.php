<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;
use Foreningssystem\Domain\Person\PersonStatus;

final class WpdbPersonRepository implements PersonRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_person';
    }

    public function add(Person $person): Person
    {
        global $wpdb;

        $data = [
            'first_name' => $person->firstName(),
            'last_name' => $person->lastName(),
            'email' => $person->email(),
            'status' => $person->status()->value,
        ];
        $format = ['%s', '%s', '%s', '%s'];

        if ($person->birthDate() !== null) {
            $data['birth_date'] = $person->birthDate()->iso();
            $format[] = '%s';
        }

        $inserted = $wpdb->insert($this->table(), $data, $format);

        if ($inserted === false) {
            throw new \RuntimeException('The person could not be saved.');
        }

        return $person->withId((int) $wpdb->insert_id);
    }

    public function save(Person $person): void
    {
        global $wpdb;

        $id = $person->id();

        if ($id === null) {
            throw new \RuntimeException('Person was not saved.');
        }

        $userId = $person->wordpressUserId();
        $table = $this->table();
        $sql = "UPDATE {$table} SET first_name = %s, last_name = %s, email = %s, status = %s, birth_date = "
            . ($person->birthDate() === null ? 'NULL' : '%s')
            . ', wp_user_id = '
            . ($userId === null ? 'NULL' : '%d')
            . ' WHERE id = %d';
        $args = [$person->firstName(), $person->lastName(), $person->email(), $person->status()->value];

        if ($person->birthDate() !== null) {
            $args[] = $person->birthDate()->iso();
        }

        if ($userId !== null) {
            $args[] = $userId;
        }

        $args[] = $id;
        $updated = $wpdb->query($wpdb->prepare($sql, ...$args));

        if ($updated === false) {
            throw new \RuntimeException('The person could not be saved.');
        }
    }

    public function find(int $id): ?Person
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function findByWordpressUserId(int $userId): ?Person
    {
        if ($userId < 1) {
            return null;
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE wp_user_id = %d LIMIT 2', $userId),
            ARRAY_A
        );

        if (! is_array($rows) || count($rows) !== 1) {
            return null;
        }

        return $this->map($rows[0]);
    }

    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SELECT * FROM ' . $this->table() . ' ORDER BY last_name, first_name, id', ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_map($this->map(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Person
    {
        $wordpressUserId = $row['wp_user_id'];

        return new Person(
            (int) $row['id'],
            (string) $row['first_name'],
            (string) $row['last_name'],
            (string) $row['email'],
            PersonStatus::from((string) $row['status']),
            is_numeric($wordpressUserId) && (int) $wordpressUserId > 0 ? (int) $wordpressUserId : null,
            $this->birthDate($row['birth_date'] ?? null)
        );
    }

    private function birthDate(mixed $value): ?AssociationDate
    {
        if (! is_string($value) || $value === '' || $value === '0000-00-00') {
            return null;
        }

        return AssociationDate::fromIso($value);
    }
}
