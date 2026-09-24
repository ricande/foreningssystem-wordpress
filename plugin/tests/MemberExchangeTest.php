<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\MemberExchange;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MemberExchangeTest extends TestCase
{
    public function test_export_and_import_keep_every_period_and_skip_numbers_that_exist(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $exchange = $this->exchange($people, $memberships, [Capabilities::EDIT_MEMBERS, Capabilities::EXPORT_MEMBERS]);
        $csv = <<<'CSV'
first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on
=Ada;Lovelace;ada-csv@example.test;known;M-1;ordinarie;ended;2020-01-01;2021-12-31
Annat;Namn;ada-csv@example.test;known;M-2;ordinarie;active;2024-01-01;
CSV;
        $first = $exchange->import($csv);
        $exported = $exchange->export();
        $again = $exchange->import(str_replace('2021-12-31', '2022-01-01', $csv));
        $overlap = $exchange->import(<<<'CSV'
first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on
Ada;Lovelace;ada-csv@example.test;known;M-3;ordinarie;active;2024-06-01;
CSV);
        $person = $people->all()[0] ?? null;

        $endedOn = null;

        foreach ($memberships->all() as $period) {
            if ($period->number() === 'M-1') {
                $endedOn = $period->endedOn()?->iso();
            }
        }

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
        self::assertSame(['Line 2: More than one person has this email.'], $ambiguous->errors());
        self::assertSame(0, $ambiguous->created());
        self::assertSame(1, $changed->skipped());
        self::assertSame(1, $changed->created());
        self::assertSame('', $people->all()[0]->email());
        self::assertSame('Ada', $people->all()[0]->firstName());
        self::assertCount(5, $people->all());
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
            }
        );
    }
}
