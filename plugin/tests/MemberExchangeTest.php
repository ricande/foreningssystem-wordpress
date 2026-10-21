<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\MemberExchange;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MemberExchangeTest extends TestCase
{
    private const EMAIL_NAME_CONFLICT = 'Not imported. This email belongs to a person with another name or birth date in the register.'
        . ' Correct the email or the name in the file, or change the person in the register first.';

    public function test_export_and_import_keep_every_period_and_skip_numbers_that_exist(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $exchange = $this->exchange($people, $memberships, [Capabilities::EDIT_MEMBERS, Capabilities::EXPORT_MEMBERS]);
        $csv = <<<'CSV'
first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on
=Ada;Lovelace;ada-csv@example.test;known;M-1;ordinarie;ended;2020-01-01;2021-12-31
=ADA;lovelace;ADA-csv@example.test;known;M-2;ordinarie;active;2024-01-01;
CSV;
        $first = $exchange->import($csv);
        $exported = $exchange->export();
        $again = $exchange->import(str_replace('2021-12-31', '2022-01-01', $csv));
        $overlap = $exchange->import(<<<'CSV'
first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on
=Ada;Lovelace;ada-csv@example.test;known;M-3;ordinarie;active;2024-06-01;
CSV);
        $person = $people->all()[0] ?? null;

        $account = $memberships->findMembershipByNumber('M-1');
        $endedOn = $account === null ? null : ($memberships->periodsForMembership((int) $account->id())[0]->endedOn()?->iso());

        self::assertSame(2, $first->created());
        self::assertSame([], $first->errors());
        self::assertNotNull($person);
        self::assertSame('=Ada', $person->firstName());
        self::assertSame(PersonStatus::Known, $person->status());
        self::assertNull($person->wordpressUserId());
        self::assertCount(1, $people->all());
        self::assertCount(2, $memberships->all());
        self::assertStringStartsWith("\xEF\xBB\xBF" . 'first_name;', $exported);
        self::assertStringContainsString("'=Ada", $exported);
        self::assertStringNotContainsString('wordpress', $exported);
        self::assertStringContainsString('2021-12-31', $exported);
        self::assertSame(0, $again->created());
        self::assertSame(2, $again->skipped());
        self::assertSame('2021-12-31', $endedOn);
        self::assertSame(0, $overlap->created());
        self::assertSame(['Line 2: Membership periods cannot overlap.'], $overlap->errors());
        self::assertCount(2, $memberships->all());
    }

    public function test_email_does_not_merge_people(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $exchange = $this->exchange($people, $memberships, [Capabilities::EDIT_MEMBERS]);
        $blank = $exchange->import(<<<'CSV'
first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on
Ada;Ett;;known;M-1;ordinarie;active;2024-01-01;
Bea;Tva;;known;M-2;ordinarie;active;2024-01-01;
CSV);
        $people->add(new Person(null, 'Cara', 'Delad', 'samma@example.test', PersonStatus::Known, null));
        $people->add(new Person(null, 'Dan', 'Delad', 'samma@example.test', PersonStatus::Known, null));
        $ambiguous = $exchange->import(<<<'CSV'
first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on
Ny;Person;samma@example.test;known;M-3;ordinarie;active;2024-02-01;
CSV);
        $changed = $exchange->import(<<<'CSV'
first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on
Ada;Bytt;ny@example.test;known;M-1;ordinarie;active;2024-01-01;
Ada;Ny;ny@example.test;known;M-4;ordinarie;ended;2020-01-01;2020-12-31
CSV);

        self::assertSame(2, $blank->created());
        self::assertSame(['Line 2: ' . self::EMAIL_NAME_CONFLICT], $ambiguous->errors());
        self::assertSame(0, $ambiguous->created());
        self::assertSame(1, $changed->skipped());
        self::assertSame(1, $changed->created());
        self::assertSame('', $people->all()[0]->email());
        self::assertSame('Ada', $people->all()[0]->firstName());
        self::assertCount(5, $people->all());
    }

    public function test_a_mistyped_email_in_the_plain_file_does_not_reach_another_person(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $exchange = $this->exchange($people, $memberships, [Capabilities::EDIT_MEMBERS]);
        $ada = (int) $people->add(new Person(null, 'Åsa', 'Lovelace', 'ada@example.test', PersonStatus::Known, null))->id();

        $result = $exchange->import(<<<'CSV'
first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on
Grace;Hopper;ada@example.test;known;M-9;ordinarie;active;2024-01-01;
ÅSA;lovelace; Ada@Example.test ;known;M-10;ordinarie;active;2024-01-01;
CSV);

        self::assertSame(['Line 2: ' . self::EMAIL_NAME_CONFLICT], $result->errors());
        self::assertSame(1, $result->created());
        self::assertNull($memberships->findMembershipByNumber('M-9'));
        self::assertCount(1, $people->all());
        $account = $memberships->findMembershipByNumber('M-10');
        self::assertNotNull($account);
        self::assertSame($ada, $memberships->participantsForMembership((int) $account->id())[0]->personId());
    }

    public function test_the_structured_file_matches_email_with_name_and_birth_date(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $exchange = $this->exchange($people, $memberships, [Capabilities::EDIT_MEMBERS]);
        $ada = (int) $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null, AssociationDate::fromIso('1990-01-01')))->id();

        $result = $exchange->import(<<<'CSV'
# foreningsplugin-members 2
membership;S-2;ordinary;
period;S-2;active;2024-01-01;;ordinary
participant;S-2;Ada;Lovelace;ada@example.test;1991-01-01;member;1;2024-01-01;;known
membership;S-3;ordinary;
period;S-3;active;2024-01-01;;ordinary
participant;S-3;Ada;Lovelace;ada@example.test;;member;1;2024-01-01;;known
membership;S-4;ordinary;
period;S-4;active;2024-01-01;;ordinary
participant;S-4;Bea;Annan;ada@example.test;;member;1;2024-01-01;;known
CSV);

        self::assertSame(['Line 4: ' . self::EMAIL_NAME_CONFLICT, 'Line 10: ' . self::EMAIL_NAME_CONFLICT], $result->errors());
        self::assertCount(1, $people->all());
        self::assertSame([], $memberships->participantsForMembership((int) $memberships->findMembershipByNumber('S-2')?->id()));
        self::assertSame($ada, $memberships->participantsForMembership((int) $memberships->findMembershipByNumber('S-3')?->id())[0]->personId());
        self::assertSame([], $memberships->participantsForMembership((int) $memberships->findMembershipByNumber('S-4')?->id()));
    }

    public function test_a_family_that_shares_one_email_comes_back_as_separate_people(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $exchange = $this->exchange($people, $memberships, [Capabilities::EDIT_MEMBERS]);
        $family = <<<'CSV'
# foreningsplugin-members 2
membership;F-1;family;
period;F-1;active;2024-01-01;;family
participant;F-1;Bo;Familj;familj@example.test;;member;1;2024-01-01;;known
participant;F-1;Cia;Familj;familj@example.test;;member;0;2024-01-01;;known
CSV;

        $first = $exchange->import($family);
        $again = $exchange->import($family);
        $later = $exchange->import(<<<'CSV'
# foreningsplugin-members 2
membership;F-2;ordinary;
period;F-2;active;2023-01-01;2023-12-31;ordinary
participant;F-2;Cia;Familj;familj@example.test;;member;1;2023-01-01;2023-12-31;known
membership;F-3;ordinary;
period;F-3;active;2023-01-01;2023-12-31;ordinary
participant;F-3;Dag;Granne;familj@example.test;;member;1;2023-01-01;2023-12-31;known
CSV);

        self::assertSame([], $first->errors());
        self::assertSame(4, $first->created());
        self::assertCount(2, $people->all());
        self::assertSame(0, $again->created());
        self::assertSame([], $again->errors());
        self::assertCount(2, $people->all());
        $cia = null;

        foreach ($people->all() as $person) {
            if ($person->firstName() === 'Cia') {
                $cia = $person->id();
            }
        }

        self::assertSame(['Line 7: ' . self::EMAIL_NAME_CONFLICT], $later->errors());
        self::assertSame($cia, $memberships->participantsForMembership((int) $memberships->findMembershipByNumber('F-2')?->id())[0]->personId());
        self::assertCount(2, $people->all());
    }

    public function test_a_file_without_the_member_columns_imports_nothing(): void
    {
        $people = new MemoryPersonRepository();
        $exchange = $this->exchange($people, new MemoryMembershipRepository(), [Capabilities::EDIT_MEMBERS]);

        try {
            $exchange->import("name,email\nAda,ada@example.test\n");
            self::fail('A comma-separated file should be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $people->all());
        }
    }

    public function test_export_is_refused_without_the_export_capability(): void
    {
        $exchange = $this->exchange(new MemoryPersonRepository(), new MemoryMembershipRepository(), [Capabilities::VIEW_MEMBERS]);

        $this->expectException(NotAllowed::class);
        $exchange->export();
    }

    public function test_import_is_refused_without_edit_members(): void
    {
        $exchange = $this->exchange(new MemoryPersonRepository(), new MemoryMembershipRepository(), [Capabilities::EXPORT_MEMBERS]);

        $this->expectException(NotAllowed::class);
        $exchange->import("first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on\n");
    }

    public function test_a_deceased_person_cannot_be_imported_with_an_open_period(): void
    {
        $people = new MemoryPersonRepository();
        $exchange = $this->exchange($people, new MemoryMembershipRepository(), [Capabilities::EDIT_MEMBERS]);
        $result = $exchange->import(<<<'CSV'
first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on
Bea;Sen;bea-csv@example.test;deceased;M-9;ordinarie;active;2024-01-01;
CSV);

        self::assertSame(0, $result->created());
        self::assertSame(['Line 2: A deceased person cannot have an open membership.'], $result->errors());
        self::assertSame([], $people->all());
    }

    /**
     * @param list<string> $capabilities
     */
    private function exchange(MemoryPersonRepository $people, MemoryMembershipRepository $memberships, array $capabilities): MemberExchange
    {
        return new MemberExchange(
            $people,
            $memberships,
            new MembershipLedger(),
            new class ($capabilities) implements Authorizer {
                /** @param list<string> $capabilities */
                public function __construct(private array $capabilities)
                {
                }

                public function allows(string $capability): bool
                {
                    return in_array($capability, $this->capabilities, true);
                }
            },
            new class implements Transaction {
                public function run(callable $callback): mixed
                {
                    return $callback();
                }
            },
            new MemoryOrganizationRepository(),
            new UnheldRegisterLock()
        );
    }
}
