<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

/**
 * Adds street address, postal code, city and phone to a person. Existing people get
 * empty values. A column that is already there is left as it is. See ADR-0035.
 */
final class PersonContactSchemaMigration implements Migration
{
    private const COLUMNS = [
        'street_address' => "varchar(190) NOT NULL DEFAULT '' AFTER email",
        'postal_code' => "varchar(20) NOT NULL DEFAULT '' AFTER street_address",
        'city' => "varchar(100) NOT NULL DEFAULT '' AFTER postal_code",
        'phone' => "varchar(40) NOT NULL DEFAULT '' AFTER city",
    ];

    public function __construct(
        private readonly string $prefix,
    ) {
    }

    public function version(): int
    {
        return 18;
    }

    public function up(): void
    {
        global $wpdb;

        $person = $this->prefix . 'assoc_person';

        foreach (self::COLUMNS as $column => $definition) {
            $existing = $wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM ' . $person . ' LIKE %s', $column), ARRAY_A);

            if (is_array($existing) && $existing !== []) {
                continue;
            }

            $added = $wpdb->query('ALTER TABLE ' . $person . ' ADD COLUMN ' . $column . ' ' . $definition);

            if ($added === false) {
                throw new MigrationException('The person contact columns could not be added.');
            }
        }
    }
}
