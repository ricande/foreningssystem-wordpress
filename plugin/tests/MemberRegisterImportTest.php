<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\MemberCsvColumns;
use Foreningssystem\Application\People\MemberCsvException;
use Foreningssystem\Application\People\MemberCsvParser;
use Foreningssystem\Application\People\MemberRegisterImport;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use PHPUnit\Framework\TestCase;

final class MemberRegisterImportTest extends TestCase
{
    private MemberCsvParser $parser;

    protected function setUp(): void
    {
        if (! class_exists(MemoryPersonRepository::class, false)) {
            require_once __DIR__ . '/PeopleServiceTest.php';
        }

        $this->parser = new MemberCsvParser();
    }

    public function test_comma_semicolon_bom_line_endings_quotes_and_swedish_characters(): void
    {
        $comma = $this->parser->parse("first_name,last_name,membership_number,membership_type,started_on\nÅsa,Öberg,M-1,ordinary,2020-01-01\n");
        $this->assertSame(',', $comma->delimiter);
        $this->assertSame('Åsa', $comma->rows[0]['cells'][0]);
        $this->assertSame(2, $comma->rows[0]['line']);

        $semicolon = $this->parser->parse("Förnamn;Efternamn;Medlemsnummer\r\nÅsa;Öberg;M-2\r\n");
        $this->assertSame(';', $semicolon->delimiter);
        $this->assertSame('Öberg', $semicolon->rows[0]['cells'][1]);

        $bom = $this->parser->parse("\xEF\xBB\xBF" . "first_name,last_name\nÅsa,Berg\n");
        $this->assertSame('first_name', $bom->headers[0]);
        $this->assertSame('Åsa', $bom->rows[0]['cells'][0]);

        $quotedComma = $this->parser->parse("first_name,last_name,membership_number,membership_type,started_on\nAnna,\"Berg, Jr\",M-3,ordinary,2020-01-01\n");
        $this->assertSame('Berg, Jr', $quotedComma->rows[0]['cells'][1]);

        $quotedSemicolon = $this->parser->parse("first_name;last_name;membership_number\nAnna;\"Berg; Jr\";M-4\n");
        $this->assertSame('Berg; Jr', $quotedSemicolon->rows[0]['cells'][1]);

        $escaped = $this->parser->parse("first_name,last_name\n\"Ann \"\"A\"\" Berg\",Lind\n");
        $this->assertSame('Ann "A" Berg', $escaped->rows[0]['cells'][0]);

        $newline = $this->parser->parse("first_name,last_name\n\"Anna\nLisa\",Berg\nNils,Berg\n");
        $this->assertSame("Anna\nLisa", $newline->rows[0]['cells'][0]);
        $this->assertSame('Nils', $newline->rows[1]['cells'][0]);
        $this->assertCount(2, $newline->rows);
    }

    public function test_empty_values_stay_empty_and_long_values_are_not_kept(): void
    {
        $table = $this->parser->parse("first_name,last_name,email\nAnna,Berg,\n");
        $this->assertSame('', $table->rows[0]['cells'][2]);

        $long = str_repeat('A', MemberCsvParser::MAX_CELL + 1);
        $oversize = $this->parser->parse("first_name,last_name\nAnna,{$long}\n");
        $this->assertTrue($oversize->rows[0]['oversize']);
        $this->assertSame('', $oversize->rows[0]['cells'][1]);
        $this->assertStringNotContainsString($long, (string) json_encode($oversize));

        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $blankEmail = $this->parser->parse($this->sheet("Anna,Berg,,M-1,ordinary,2020-01-01\n"));
        $result = $this->importer($people, $memberships, [Capabilities::EDIT_MEMBERS])->commit($blankEmail, $this->mapping(), $this->today());
        $this->assertSame(1, $result->imported);
        $this->assertSame('', $people->all()[0]->email());
    }

    public function test_malformed_oversized_and_too_wide_files_are_rejected(): void
    {
        try {
            $this->parser->parse("first_name,last_name\nAnna,\xFF\n");
            $this->fail('Invalid UTF-8 was accepted.');
        } catch (MemberCsvException $error) {
            $this->assertSame('not_utf8', $error->reason());
        }

        try {
            $this->parser->parse(str_repeat('a', MemberCsvParser::MAX_BYTES + 1));
            $this->fail('A file past the size limit was accepted.');
        } catch (MemberCsvException $error) {
            $this->assertSame('too_large', $error->reason());
        }

        $line = "Anna,Berg,19900101-1234,%d,ordinary,2020-01-01\n";
        $rows = "first_name,last_name,email,membership_number,membership_type,started_on\n";

        for ($index = 1; $index <= MemberCsvParser::MAX_ROWS + 1; $index++) {
            $rows .= sprintf($line, $index);
        }

        try {
            $this->parser->parse($rows);
            $this->fail('Too many rows were accepted.');
        } catch (MemberCsvException $error) {
            $this->assertSame('too_many_rows', $error->reason());
            $this->assertSame('too_many_rows', $error->getMessage());
            $this->assertStringNotContainsString('19900101-1234', $error->getMessage());
        }

        $wide = implode(',', array_fill(0, MemberCsvParser::MAX_COLUMNS + 1, 'column'));

        try {
            $this->parser->parse($wide . "\n" . implode(',', array_fill(0, MemberCsvParser::MAX_COLUMNS + 1, 'x')));
            $this->fail('Too many columns were accepted.');
        } catch (MemberCsvException $error) {
            $this->assertSame('too_many_columns', $error->reason());
        }
    }

    public function test_preview_performs_no_persistent_writes(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $import = $this->importer($people, $memberships, [Capabilities::EDIT_MEMBERS]);
        $table = $this->parser->parse($this->sheet("Anna,Berg,anna@example.test,M-1,ordinary,2020-01-01\n"));
        $plan = $import->plan($table, $this->mapping(), $this->today());

        $this->assertSame(1, $plan->ready());
        $this->assertSame([], $people->all());
        $this->assertSame([], $memberships->allMemberships());
        $this->assertSame([], $memberships->allParticipants());
        $this->assertSame([], $memberships->all());
    }

    public function test_successful_import_creates_a_person_without_a_wordpress_user(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $import = $this->importer($people, $memberships, [Capabilities::EDIT_MEMBERS]);
        $csv = "Förnamn,Efternamn,E-post,Medlemsnummer,Medlemstyp,Startdatum,Födelsedatum\n"
            . "Åsa,Öberg,asa@example.test,M-7,Ungdom,15.01.2020,01.02.2015\n";
        $table = $this->parser->parse($csv);
        $result = $import->commit($table, $this->auto($table), $this->today());

        $this->assertSame(1, $result->imported);
        $this->assertCount(1, $people->all());
        $person = $people->all()[0];
        $this->assertSame('Åsa', $person->firstName());
        $this->assertSame('Öberg', $person->lastName());
        $this->assertNull($person->wordpressUserId());
        $this->assertSame('2015-02-01', $person->birthDate()?->iso());
        $this->assertSame(MembershipKind::Youth, $memberships->allMemberships()[0]->kind());
        $this->assertSame('2020-01-15', $memberships->all()[0]->startedOn()->iso());
    }

    public function test_missing_required_columns_and_invalid_membership_data_write_nothing(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $import = $this->importer($people, $memberships, [Capabilities::EDIT_MEMBERS]);
        $table = $this->parser->parse($this->sheet("Anna,Berg,anna@example.test,M-1,ordinary,2020-01-01\n"));
        $mapping = $this->mapping();
        unset($mapping[0]);
        $blocked = $import->plan($table, $mapping, $this->today());
        $this->assertContains('map_first_name', $blocked->blockers);
        $this->assertSame(0, $import->commit($table, $mapping, $this->today())->imported);

        $invalid = "first_name,last_name,email,membership_number,membership_type,membership_status,started_on,ended_on,birth_date\n"
            . "Anna,Berg,not-an-email,M-2,ordinary,active,2020-01-01,,\n"
            . "Ida,Berg,ida@example.test,M-9,VIP,active,2020-01-01,,\n"
            . "Bo,Berg,bo@example.test,M-3,Företag,active,2020-01-01,,\n"
            . "Cia,Berg,cia@example.test,M-4,Ungdom,active,2020-01-01,,\n"
            . "Dan,Berg,dan@example.test,M-5,ordinary,ended,2020-01-01,,\n"
            . "Eva,Berg,eva@example.test,M-6,ordinary,active,2020-01-01,2021-01-01,\n"
            . "Fia,Berg,fia@example.test,M-7,ordinary,active,32.13.2020,,\n"
            . "Gia,Berg,gia@example.test,M-8,ordinary,active,2020-01-01,,\n";
        $invalidTable = $this->parser->parse($invalid);
        $result = $import->commit($invalidTable, $this->auto($invalidTable), $this->today());

        $this->assertSame(1, $result->imported);
        $this->assertSame(['Gia'], array_map(static fn (Person $person): string => $person->firstName(), $people->all()));
        $codes = array_map(static fn ($issue): string => $issue->code, $result->issues);
        $this->assertContains('invalid_email', $codes);
        $this->assertContains('unknown_type', $codes);
        $this->assertContains('company', $codes);
        $this->assertContains('youth_birth', $codes);
        $this->assertContains('ended_needs_date', $codes);
        $this->assertContains('open_has_end', $codes);
        $this->assertContains('invalid_date', $codes);
        $this->assertCount(1, $memberships->allMemberships());
        $this->assertCount(1, $memberships->allParticipants());
        $this->assertCount(1, $memberships->all());
    }

    public function test_duplicates_are_flagged_and_not_merged(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $import = $this->importer($people, $memberships, [Capabilities::EDIT_MEMBERS]);
        $today = $this->today();
        $import->commit($this->parser->parse($this->sheet("Anna,Berg,anna@example.test,M-1,ordinary,2020-01-01\n")), $this->mapping(), $today);

        $again = $this->parser->parse(
            "first_name,last_name,email,membership_number,membership_type,started_on\n"
            . "Anna,Berg,anna@example.test,M-2,ordinary,2020-01-01\n"
            . "Anna,Other,other@example.test,M-1,ordinary,2021-01-01\n"
            . "Bo,Berg,bo@example.test,M-3,ordinary,2020-01-01\n"
            . "Bo,Berg,bo@example.test,M-4,ordinary,2020-01-01\n"
            . "Cia,Lind,cia@example.test,M-5,ordinary,2020-01-01\n"
            . "Cia,Lind,cia@example.test,M-5,ordinary,2021-01-01\n"
        );
        $plan = $import->plan($again, $this->mapping(), $today);
        $codes = array_map(static fn ($issue): string => $issue->kind . ':' . $issue->code . ':' . $issue->line, $plan->issues);

        $this->assertContains('duplicate:email_exists:2', $codes);
        $this->assertContains('duplicate:number_exists:3', $codes);
        $this->assertContains('duplicate:email_repeated:4', $codes);
        $this->assertContains('duplicate:email_repeated:5', $codes);
        $this->assertContains('duplicate:number_repeated:7', $codes);
        $this->assertSame(1, $plan->ready());
        $this->assertSame('Cia', $plan->readyRows[0]['first_name']);

        $result = $import->commit($again, $this->mapping(), $today);
        $this->assertSame(1, $result->imported);
        $this->assertCount(2, $people->all());
        $this->assertCount(2, $memberships->allMemberships());
    }

    public function test_personal_identity_numbers_are_not_kept_in_the_plan(): void
    {
        $csv = "Förnamn;Efternamn;Personnummer;Telefon;Medlemsnummer;Medlemstyp;Startdatum\n"
            . "Anna;Berg;19900101-1234;0700000000;M-1;Ordinarie;2020-01-01\n";
        $table = $this->parser->parse($csv);
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $plan = $this->importer($people, $memberships, [Capabilities::EDIT_MEMBERS])->plan($table, $this->auto($table), $this->today());
        $encoded = (string) json_encode($plan);

        $this->assertStringNotContainsString('19900101-1234', $encoded);
        $this->assertStringNotContainsString('0700000000', $encoded);
        $this->assertSame('ignored_identity', $plan->notices[0]['code']);
        $this->assertSame('', $plan->notices[0]['header']);
        $this->assertSame(1, $plan->ready());
        $this->assertSame([], $people->all());
    }

    public function test_a_failed_row_does_not_leave_a_partial_person_or_membership(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $memberships->failNext = 'participant';
        $import = new MemberRegisterImport(
            $people,
            $memberships,
            new MembershipLedger(),
            $this->authorizer([Capabilities::EDIT_MEMBERS]),
            new RestoringMemberTransaction($people, $memberships)
        );
        $csv = "first_name,last_name,email,membership_number,membership_type,started_on\n"
            . "Anna,Berg,anna@example.test,M-1,ordinary,2020-01-01\n"
            . "Bo,Lind,bo@example.test,M-2,ordinary,2020-01-01\n";
        $result = $import->commit($this->parser->parse($csv), $this->mapping(), $this->today());

        $this->assertSame(1, $result->imported);
        $this->assertSame(1, $result->failed);
        $this->assertSame('save_failed', $result->issues[0]->code);
        $this->assertStringNotContainsString('participant insert failed', (string) json_encode($result));
        $this->assertCount(1, $people->all());
        $this->assertSame('Bo', $people->all()[0]->firstName());
        $this->assertCount(1, $memberships->allMemberships());
        $this->assertCount(1, $memberships->allParticipants());
        $this->assertCount(1, $memberships->all());
    }

    public function test_repeating_the_import_does_not_create_the_same_member_again(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $import = $this->importer($people, $memberships, [Capabilities::EDIT_MEMBERS]);
        $table = $this->parser->parse($this->sheet("Anna,Berg,anna@example.test,M-1,ordinary,2020-01-01\n"));
        $mapping = $this->mapping();
        $today = $this->today();

        $this->assertSame(1, $import->commit($table, $mapping, $today)->imported);
        $second = $import->commit($table, $mapping, $today);

        $this->assertSame(0, $second->imported);
        $this->assertSame(1, $second->duplicates);
        $this->assertCount(1, $people->all());
        $this->assertCount(1, $memberships->allMemberships());
    }

    public function test_import_requires_edit_members(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $import = $this->importer($people, $memberships, []);
        $table = $this->parser->parse($this->sheet("Anna,Berg,anna@example.test,M-1,ordinary,2020-01-01\n"));

        try {
            $import->plan($table, $this->mapping(), $this->today());
            $this->fail('Preview was allowed without edit_members.');
        } catch (NotAllowed) {
            $this->assertSame([], $people->all());
        }

        try {
            $import->commit($table, $this->mapping(), $this->today());
            $this->fail('Import was allowed without edit_members.');
        } catch (NotAllowed) {
            $this->assertSame([], $people->all());
            $this->assertSame([], $memberships->allMemberships());
        }
    }

    public function test_a_deceased_person_is_imported_only_with_an_ended_membership(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $csv = "first_name,last_name,email,membership_number,membership_type,person_status,membership_status,started_on,ended_on\n"
            . "Ada,Berg,ada@example.test,M-1,ordinary,avliden,avslutad,2020-01-01,2021-01-01\n"
            . "Bea,Berg,bea@example.test,M-2,ordinary,deceased,active,2020-01-01,\n";
        $result = $this->importer($people, $memberships, [Capabilities::EDIT_MEMBERS])->commit($this->parser->parse($csv), [
            0 => 'first_name',
            1 => 'last_name',
            2 => 'email',
            3 => 'membership_number',
            4 => 'membership_type',
            5 => 'person_status',
            6 => 'membership_status',
            7 => 'started_on',
            8 => 'ended_on',
        ], $this->today());

        $this->assertSame(1, $result->imported);
        $this->assertSame(PersonStatus::Deceased, $people->all()[0]->status());
        $this->assertNull($people->all()[0]->wordpressUserId());
        $this->assertSame('deceased_open', $result->issues[0]->code);
        $this->assertCount(1, $memberships->allMemberships());
    }

    public function test_empty_status_is_reported_as_active_and_not_silently_relabelled(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $table = $this->parser->parse("first_name,last_name,membership_number,membership_type,started_on\nAnna,Berg,M-1,ordinary,2020-01-01\n");
        $plan = $this->importer($people, $memberships, [Capabilities::EDIT_MEMBERS])->plan($table, [
            0 => 'first_name',
            1 => 'last_name',
            2 => 'membership_number',
            3 => 'membership_type',
            4 => 'started_on',
        ], $this->today());
        $codes = array_map(static fn (array $notice): string => $notice['code'], $plan->notices);

        $this->assertContains('status_defaults_active', $codes);
        $this->assertContains('person_defaults_known', $codes);
        $this->assertSame('active', $plan->readyRows[0]['membership_status']);
        $this->assertSame('known', $plan->readyRows[0]['person_status']);
    }

    /**
     * @param list<string> $capabilities
     */
    private function importer(MemoryPersonRepository $people, MemoryMembershipRepository $memberships, array $capabilities): MemberRegisterImport
    {
        return new MemberRegisterImport(
            $people,
            $memberships,
            new MembershipLedger(),
            $this->authorizer($capabilities),
            new class implements Transaction {
                public function run(callable $callback): mixed
                {
                    return $callback();
                }
            }
        );
    }

    /**
     * @param list<string> $capabilities
     */
    private function authorizer(array $capabilities): Authorizer
    {
        return new class ($capabilities) implements Authorizer {
            /** @param list<string> $capabilities */
            public function __construct(private array $capabilities)
            {
            }

            public function allows(string $capability): bool
            {
                return in_array($capability, $this->capabilities, true);
            }
        };
    }

    /**
     * @return array<int, string>
     */
    private function mapping(): array
    {
        return [
            0 => 'first_name',
            1 => 'last_name',
            2 => 'email',
            3 => 'membership_number',
            4 => 'membership_type',
            5 => 'started_on',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function auto(\Foreningssystem\Application\People\MemberCsvTable $table): array
    {
        $mapping = [];

        foreach ($table->headers as $index => $header) {
            $field = MemberCsvColumns::inspect($header)['field'];

            if ($field !== '') {
                $mapping[$index] = $field;
            }
        }

        return $mapping;
    }

    private function sheet(string $rows): string
    {
        return "first_name,last_name,email,membership_number,membership_type,started_on\n" . $rows;
    }

    private function today(): AssociationDate
    {
        return AssociationDate::fromIso('2026-09-29');
    }
}

final class RestoringMemberTransaction implements Transaction
{
    public function __construct(
        private readonly MemoryPersonRepository $people,
        private readonly MemoryMembershipRepository $memberships,
    ) {
    }

    public function run(callable $callback): mixed
    {
        $people = $this->people->people;
        $accounts = $this->memberships->accounts;
        $participants = $this->memberships->participants;
        $periods = $this->memberships->periods;

        try {
            return $callback();
        } catch (\Throwable $error) {
            $this->people->people = $people;
            $this->memberships->accounts = $accounts;
            $this->memberships->participants = $participants;
            $this->memberships->periods = $periods;

            throw $error;
        }
    }
}
