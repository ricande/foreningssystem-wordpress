<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

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

        $inserted = $wpdb->insert($this->table(), [
            'first_name' => $person->firstName(),
            'last_name' => $person->lastName(),
            'email' => $person->email(),
            'status' => $person->status()->value,
        ], ['%s', '%s', '%s', '%s']);

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
        $sql = "UPDATE {$table} SET first_name = %s, last_name = %s, email = %s, status = %s, wp_user_id = "
            . ($userId === null ? 'NULL' : '%d')
            . ' WHERE id = %d';
        $args = [$person->firstName(), $person->lastName(), $person->email(), $person->status()->value];

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
            is_numeric($wordpressUserId) && (int) $wordpressUserId > 0 ? (int) $wordpressUserId : null
        );
    }
}
