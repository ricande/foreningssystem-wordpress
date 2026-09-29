<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

/**
 * Suggests a mapping from a CSV heading to a real person or membership field.
 * The suggestion is not applied until an officer confirms it.
 */
final class MemberCsvColumns
{
    /** @var list<string> */
    public const FIELDS = [
        'first_name',
        'last_name',
        'email',
        'birth_date',
        'person_status',
        'membership_number',
        'membership_type',
        'membership_status',
        'started_on',
        'ended_on',
    ];

    /** @var list<string> */
    public const REQUIRED = [
        'first_name',
        'last_name',
        'membership_number',
        'membership_type',
        'started_on',
    ];

    /**
     * @return array{field: string, notice: string}
     */
    public static function inspect(string $header): array
    {
        $folded = self::fold($header);
        $aliases = [
            'first_name' => ['fornamn', 'förnamn', 'first name', 'first_name', 'firstname', 'given name'],
            'last_name' => ['efternamn', 'last name', 'last_name', 'lastname', 'surname'],
            'email' => ['e-post', 'epost', 'email', 'e-mail', 'mail'],
            'birth_date' => ['fodelsedatum', 'födelsedatum', 'birth date', 'birth_date', 'född'],
            'person_status' => ['personstatus', 'person status', 'person_status'],
            'membership_number' => ['medlemsnummer', 'membership number', 'membership_number', 'member number'],
            'membership_type' => ['medlemstyp', 'membership type', 'membership_type', 'typ'],
            'membership_status' => ['medlemsstatus', 'membership status', 'membership_status', 'status'],
            'started_on' => ['startdatum', 'start date', 'started_on', 'start', 'inträde', 'intrade'],
            'ended_on' => ['slutdatum', 'end date', 'ended_on', 'slut'],
        ];

        foreach ($aliases as $field => $names) {
            if (in_array($folded, $names, true)) {
                return ['field' => $field, 'notice' => ''];
            }
        }

        $identity = ['personnummer', 'personal identity number', 'personal_identity_number', 'personnr', 'pnr'];

        if (in_array($folded, $identity, true)) {
            return ['field' => '', 'notice' => 'identity'];
        }

        $unsupported = [
            'telefon', 'phone', 'tel', 'mobil',
            'adress', 'address', 'gatuadress',
            'postnummer', 'postal code', 'zip',
            'ort', 'city', 'stad',
            'namn', 'name',
        ];

        if (in_array($folded, $unsupported, true)) {
            return ['field' => '', 'notice' => 'unsupported'];
        }

        return ['field' => '', 'notice' => ''];
    }

    public static function knownField(string $field): bool
    {
        return in_array($field, self::FIELDS, true);
    }

    private static function fold(string $header): string
    {
        return mb_strtolower(trim($header), 'UTF-8');
    }
}
