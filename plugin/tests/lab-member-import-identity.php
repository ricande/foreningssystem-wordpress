<?php

/**
 * Who an imported row is about. The own member files attach a row to a stored person
 * only when email and name agree (ADR-0030). The spreadsheet import checks the email
 * again inside each row's hold of the register (ADR-0031). Synthetic people only.
 */

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\MemberCsvParser;
use Foreningssystem\Application\People\MemberRegisterImport;
use Foreningssystem\Application\People\MemberRegisterLock;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Infrastructure\WordPress\WordpressMemberRegisterLock;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;
use Foreningssystem\Infrastructure\WordPress\WpdbMembershipRepository;
use Foreningssystem\Infrastructure\WordPress\WpdbPersonRepository;

require __DIR__ . '/lab-membership.php';

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$tables = lab_membership_tables();

$cleanup = static function () use ($wpdb, $people): void {
    lab_delete_membership_numbers('LAB-IDENT-%');
    $wpdb->query($wpdb->prepare("DELETE FROM {$people} WHERE email LIKE %s", 'lab-ident-%@example.test'));
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$personIdsFor = static function (string $email) use ($wpdb, $people): array {
    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s ORDER BY id", $email)));
};

$conflict = 'Not imported. This email belongs to a person with another name or birth date in the register.'
    . ' Correct the email or the name in the file, or change the person in the register first.';

$cleanup();
wp_set_current_user(1);
$today = AssociationDate::fromIso('2025-01-01');
$asa = WordpressPeople::service()->register('Åsa', 'Ident', 'lab-ident-asa@example.test', 'LAB-IDENT-1', 'ordinary', AssociationDate::fromIso('2020-01-01'), null, $today);

// Plain file: a mistyped address is reported, the same name in other letter case attaches.
$plain = WordpressPeople::exchange()->import(<<<'CSV'
first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on
Grace;Hopper;lab-ident-asa@example.test;known;LAB-IDENT-2;ordinary;active;2024-01-01;
ÅSA;ident;LAB-IDENT-ASA@example.test;known;LAB-IDENT-3;ordinary;ended;2010-01-01;2010-12-31
CSV);

if ($plain->errors() !== ['Line 2: ' . $conflict] || $plain->created() !== 1) {
    $fail('The plain file should refuse the other name and attach the same name. Errors: ' . wp_json_encode($plain->errors()));
}

if (lab_person_id_for_membership_number('LAB-IDENT-2') !== 0 || lab_person_id_for_membership_number('LAB-IDENT-3') !== $asa) {
    $fail('A membership was put on the wrong person.');
}

// Structured file: a family that shares one address comes back as two people, and
// survives export, removal and restore. Another name on another membership is refused.
$family = <<<'CSV'
# foreningsplugin-members 2
membership;LAB-IDENT-F;family;
period;LAB-IDENT-F;active;2024-01-01;;family
participant;LAB-IDENT-F;Bo;Familj;lab-ident-family@example.test;;member;1;2024-01-01;;known
participant;LAB-IDENT-F;Cia;Familj;lab-ident-family@example.test;;member;0;2024-01-01;;known
membership;LAB-IDENT-X;ordinary;
period;LAB-IDENT-X;active;2024-01-01;;ordinary
participant;LAB-IDENT-X;Dag;Granne;lab-ident-family@example.test;;member;1;2024-01-01;;known
CSV;
$first = WordpressPeople::exchange()->import($family);

if ($first->errors() !== ['Line 8: ' . $conflict] || count($personIdsFor('lab-ident-family@example.test')) !== 2) {
    $fail('The family should be two people and the neighbour refused. Errors: ' . wp_json_encode($first->errors()));
}

$again = WordpressPeople::exchange()->import($family);

if ($again->created() !== 0 || count($personIdsFor('lab-ident-family@example.test')) !== 2) {
    $fail('Importing the family again changed the register.');
}

$exported = WordpressPeople::exchange()->exportStructure();
$subset = implode("\n", array_merge(
    ['# foreningsplugin-members 2'],
    array_values(array_filter(
        preg_split('/\r?\n/', ltrim($exported, "\xEF\xBB\xBF")) ?: [],
        static fn (string $line): bool => preg_match('/^[a-z]+;LAB-IDENT-F;/', $line) === 1
    ))
));
lab_delete_membership_numbers('LAB-IDENT-F');
$wpdb->query($wpdb->prepare("DELETE FROM {$people} WHERE email = %s", 'lab-ident-family@example.test'));
$restored = WordpressPeople::exchange()->import($subset);
$familyIds = $personIdsFor('lab-ident-family@example.test');
$names = array_map(
    static fn (int $id): string => (string) (new WpdbPersonRepository())->find($id)?->firstName(),
    $familyIds
);
sort($names);

if ($restored->errors() !== [] || $names !== ['Bo', 'Cia']) {
    $fail('The family did not come back from the export as two people: ' . wp_json_encode([$restored->errors(), $names]));
}

// Spreadsheet import: an address saved by someone else after the preview is found inside the hold.
$lock = new class (new WordpressMemberRegisterLock()) implements MemberRegisterLock {
    public bool $interfered = false;

    public function __construct(private readonly MemberRegisterLock $inner)
    {
    }

    public function hold(callable $work): mixed
    {
        if (! $this->interfered) {
            $this->interfered = true;
            WordpressPeople::service()->rememberPerson('Eva', 'Hinner', 'LAB-IDENT-EVA@example.test', null, AssociationDate::fromIso('2025-01-01'));
        }

        return $this->inner->hold($work);
    }
};
$import = new MemberRegisterImport(
    new WpdbPersonRepository(),
    new WpdbMembershipRepository(),
    new MembershipLedger(),
    new class implements Authorizer {
        public function allows(string $capability): bool
        {
            return current_user_can($capability);
        }
    },
    new class implements Transaction {
        public function run(callable $callback): mixed
        {
            global $wpdb;

            $wpdb->query('START TRANSACTION');

            try {
                $result = $callback();
                $wpdb->query('COMMIT');

                return $result;
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');

                throw $error;
            }
        }
    },
    $lock
);
$table = (new MemberCsvParser())->parse("first_name,last_name,email,membership_number,membership_type,started_on\n"
    . "Eva,Hinner,lab-ident-eva@example.test,LAB-IDENT-E,ordinary,2024-01-01\n"
    . "Fia,Fri,lab-ident-fia@example.test,LAB-IDENT-G,ordinary,2024-01-01\n");
$mapping = [0 => 'first_name', 1 => 'last_name', 2 => 'email', 3 => 'membership_number', 4 => 'membership_type', 5 => 'started_on'];
$plan = $import->plan($table, $mapping, $today);
$result = $import->commit($table, $mapping, $today);
$codes = array_map(static fn ($issue): string => $issue->code, $result->issues);

if (count($plan->readyRows) !== 2 || $result->imported !== 1 || $result->duplicates !== 1 || $codes !== ['email_exists']) {
    $fail('The spreadsheet import should find the address saved after the preview. ' . wp_json_encode([$result->imported, $result->duplicates, $codes]));
}

if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['membership']} WHERE membership_number = %s", 'LAB-IDENT-E')) !== '0'
    // The column collation ignores case, so this finds both spellings.
    || count($personIdsFor('lab-ident-eva@example.test')) !== 1
    || lab_person_id_for_membership_number('LAB-IDENT-G') === 0) {
    $fail('The refused row left data behind, or the other row was not imported.');
}

$cleanup();
\WP_CLI::success('Imports attach a row only to the person whose email and name agree, and the spreadsheet import checks the email inside the hold.');
