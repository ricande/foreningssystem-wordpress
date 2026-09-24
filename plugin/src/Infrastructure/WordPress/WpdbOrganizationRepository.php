<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Organization\Organization;
use Foreningssystem\Domain\Organization\OrganizationNumber;
use Foreningssystem\Domain\Organization\OrganizationRepository;

final class WpdbOrganizationRepository implements OrganizationRepository
{
    public function add(Organization $organization): Organization
    {
        global $wpdb;

        $inserted = $wpdb->insert($this->table(), [
            'name' => $organization->name(),
            'organization_number' => $organization->number()?->canonical(),
            'email' => $organization->email(),
            'postal_address' => $organization->postalAddress(),
        ], ['%s', '%s', '%s', '%s']);

        if ($inserted === false) {
            throw new \RuntimeException('The organization could not be saved.');
        }

        return $organization->withId((int) $wpdb->insert_id);
    }

    public function find(int $id): ?Organization
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function findByNumber(string $canonical): ?Organization
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE organization_number = %s', $canonical), ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SELECT * FROM ' . $this->table() . ' ORDER BY name, id', ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_map($this->map(...), $rows);
    }

    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_organization';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Organization
    {
        $number = (string) ($row['organization_number'] ?? '');

        return new Organization(
            (int) $row['id'],
            (string) $row['name'],
            $number === '' ? null : OrganizationNumber::parse($number),
            (string) $row['email'],
            (string) $row['postal_address']
        );
    }
}
