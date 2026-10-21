<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\PeopleService;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Application\Privacy\PrivacyErase;
use Foreningssystem\Application\Privacy\PrivacyExport;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\AuditEvent;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\ContactDetails;
use Foreningssystem\Domain\Person\PersonStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Street address, postal code, city and phone on a person. See ADR-0035.
 */
final class PersonContactDetailsTest extends TestCase
{
    private const TODAY = '2025-03-01';

    public function test_the_columns_arrive_with_schema_18(): void
    {
        self::assertSame(18, (new \Foreningssystem\Infrastructure\Persistence\PersonContactSchemaMigration('wp_'))->version());
    }

    public function test_parts_are_trimmed_and_inner_whitespace_is_collapsed(): void
    {
        $contact = new ContactDetails("  Storgatan\t 1 ", ' 111  22', 'Stockholm ', ' +46 (0)8-123 45 ');

        self::assertSame('Storgatan 1', $contact->streetAddress());
        self::assertSame('111 22', $contact->postalCode());
        self::assertSame('Stockholm', $contact->city());
        self::assertSame('+46 (0)8-123 45', $contact->phone());
        self::assertFalse($contact->isEmpty());
        self::assertTrue((new ContactDetails())->isEmpty());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidPhones(): iterable
    {
        yield 'letters' => ['070-ABC'];
        yield 'plus in the middle' => ['070+123'];
        yield 'markup' => ['<b>070</b>'];
        yield 'only punctuation' => ['--'];
    }

    #[DataProvider('invalidPhones')]
    public function test_a_phone_number_with_other_characters_is_refused(string $phone): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Phone number is not valid.');

        new ContactDetails('', '', '', $phone);
    }

    public function test_lengths_count_characters_not_bytes(): void
    {
        self::assertSame(100, mb_strlen((new ContactDetails('', '', str_repeat('Å', 100)))->city()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('City is too long.');

        new ContactDetails('', '', str_repeat('Å', 101));
    }

    public function test_register_stores_the_details_and_an_edit_without_them_keeps_them(): void
    {
        [$service, $people] = $this->fixture();
        $personId = $service->register('Åsa', 'Lind', 'asa@example.test', 'M-1', 'ordinary', $this->today(), null, $this->today(), new ContactDetails('Storgatan 1', '111 22', 'Stockholm', '070-123 45 67'));

        $service->updateContact($personId, 'Åsa', 'Lindqvist', 'asa@example.test', null, $this->today(), 7);

        $stored = $people->find($personId)?->contactDetails();
        self::assertSame(['Storgatan 1', '111 22', 'Stockholm', '070-123 45 67'], [$stored?->streetAddress(), $stored?->postalCode(), $stored?->city(), $stored?->phone()]);
    }

    public function test_an_address_or_phone_edit_is_recorded_without_the_values(): void
    {
        [$service, $people, $audit] = $this->fixture();
        $personId = $service->rememberPerson('Ada', 'Lovelace', '', null, $this->today(), new ContactDetails('Gata 1', '111 22', 'Ort', '0701'));

        $service->updateContact($personId, 'Ada', 'Lovelace', '', null, $this->today(), 7, null, new ContactDetails('Gata 1', '111 22', 'Ort', '0701'));
        self::assertSame([], $this->actions($audit, $personId));

        $service->updateContact($personId, 'Ada', 'Lovelace', '', null, $this->today(), 7, null, new ContactDetails('Gata 2', '111 22', 'Ort', '0701'));
        $service->updateContact($personId, 'Ada', 'Lovelace', '', null, $this->today(), 7, null, new ContactDetails('Gata 2', '111 22', 'Ort', '0702'));
        $service->updateContact($personId, 'Ada', 'Lovelace', '', null, $this->today(), 7, null, new ContactDetails());

        self::assertSame(
            ['contact_address_changed', 'contact_phone_changed', 'contact_address_changed', 'contact_phone_changed'],
            $this->actions($audit, $personId)
        );
        self::assertTrue($people->find($personId)?->contactDetails()->isEmpty());

        foreach ($audit->forObject('person', $personId) as $event) {
            self::assertStringNotContainsString('Gata', $event->action());
            self::assertStringNotContainsString('070', $event->action());
        }
    }

    public function test_the_form_version_covers_address_and_phone(): void
    {
        [$service, $people] = $this->fixture();
        $personId = $service->rememberPerson('Ada', 'Lovelace', '', null, $this->today(), new ContactDetails('Gata 1'));
        $opened = (string) $people->find($personId)?->contactVersion();

        $service->updateContact($personId, 'Ada', 'Lovelace', '', null, $this->today(), 7, null, new ContactDetails('Gata 1', '', '', '0701'));

        $this->expectException(\Foreningssystem\Application\People\ContactChanged::class);
        $service->updateContact($personId, 'Ada', 'Lovelace', '', null, $this->today(), 8, $opened, new ContactDetails('Gata 9'));
    }

    public function test_the_privacy_export_carries_the_details_and_names_the_changed_parts(): void
    {
        [$service, $people, $audit] = $this->fixture();
        $memberships = new MemoryMembershipRepository();
        $personId = $service->rememberPerson('Ada', 'Lovelace', 'ada@example.test', null, $this->today(), new ContactDetails('Gata 1', '111 22', 'Ort', '0701'));
        $memberships->grant($personId, 'M-ADA', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $service->updateContact($personId, 'Ada', 'Lovelace', 'ada@example.test', null, $this->today(), 7, null, new ContactDetails('Gata 2', '111 22', 'Ort', '0702'));
        $export = new PrivacyExport(
            $people,
            $memberships,
            new MemoryBoardAssignmentRepository(),
            new MemoryBoardRoleRepository(),
            new MemoryParticipantRepository(),
            new MemoryMeetingRepository(),
            $audit,
            new MemoryPersonalIdentityRepository(),
            new MemoryGuardianRepository()
        );

        $person = $export->collect('ada@example.test', null, 3)->people()[0];

        self::assertSame('Gata 2', $person->contactDetails()->streetAddress());
        self::assertSame('0702', $person->contactDetails()->phone());
        self::assertSame(['address', 'phone'], array_column($person->contactChanges(), 'part'));
    }

    public function test_erase_clears_address_and_phone(): void
    {
        [$service, $people, $audit] = $this->fixture();
        $personId = $service->rememberPerson('Anonym', 'Medlem', '', null, $this->today(), new ContactDetails('Gata 1', '111 22', 'Ort', '0701'));
        $stored = $people->find($personId);
        self::assertNotNull($stored);
        $people->save($stored->linkedToWordpressUser(42));

        $outcomes = $this->eraser($people, $audit)->erase('', 42, 3);

        self::assertTrue($outcomes[0]->identifiersCleared());
        self::assertTrue($people->find($personId)?->contactDetails()->isEmpty());
    }

    public function test_a_plain_member_file_from_before_address_and_phone_still_imports(): void
    {
        $register = new MemberFileRegister();
        $csv = "\xEF\xBB\xBFfirst_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on\n"
            . "Åsa;Lind;asa@example.test;known;M-1;ordinary;active;2020-01-01;\n";

        $result = $register->exchange()->import($csv);

        self::assertSame([], $result->errors());
        self::assertSame(1, $result->created());
    }

    public function test_a_plain_member_file_row_with_an_invalid_phone_is_reported_on_its_line(): void
    {
        $register = new MemberFileRegister();
        $csv = "first_name;last_name;email;person_status;membership_number;membership_type;membership_status;started_on;ended_on;street_address;postal_code;city;phone\n"
            . "Åsa;Lind;;known;M-1;ordinary;active;2020-01-01;;Gata 1;111 22;Ort;070-ABC\n"
            . "Bo;Lind;;known;M-2;ordinary;active;2020-01-01;;;;;'+46 70 1\n";

        $result = $register->exchange()->import($csv);

        self::assertSame(['Line 2: Phone number is not valid.'], $result->errors());
        self::assertSame(1, $result->created());
    }

    public function test_a_structured_participant_row_without_contact_columns_has_no_address(): void
    {
        $register = new MemberFileRegister();
        $csv = "# foreningsplugin-members 2\n"
            . "membership;M-1;ordinary;\n"
            . "period;M-1;active;2024-01-01;;ordinary\n"
            . "participant;M-1;Ada;Lovelace;ada@example.test;;member;1;2024-01-01;;known\n";

        $result = $register->exchange()->import($csv);

        self::assertSame([], $result->errors());
        self::assertSame([['Ada', 'Lovelace', 'ada@example.test', null, 'known', '', '', '', '']], $register->snapshot()['people']);
    }

    /**
     * @return array{0: PeopleService, 1: MemoryPersonRepository, 2: MemoryAuditLog}
     */
    private function fixture(): array
    {
        $people = new MemoryPersonRepository();
        $audit = new MemoryAuditLog();
        $service = new PeopleService(
            $people,
            new MemoryMembershipRepository(),
            new MembershipLedger(),
            new class implements Authorizer {
                public function allows(string $capability): bool
                {
                    return in_array($capability, [Capabilities::EDIT_MEMBERS, Capabilities::VIEW_MEMBERS], true);
                }
            },
            $this->passthrough(),
            new RecordingOpenAssignments(),
            new UnheldRegisterLock(),
            $audit
        );

        return [$service, $people, $audit];
    }

    private function eraser(MemoryPersonRepository $people, MemoryAuditLog $audit): PrivacyErase
    {
        return new PrivacyErase(
            $people,
            new MemoryMembershipRepository(),
            new MemoryBoardAssignmentRepository(),
            new MemoryParticipantRepository(),
            new MemoryMeetingRepository(),
            new MemoryMinutesRepository(),
            new MemorySignedCopyRepository(),
            $audit,
            new class implements Authorizer {
                public function allows(string $capability): bool
                {
                    return $capability === Capabilities::ERASE_MEMBER_DATA;
                }
            },
            $this->passthrough(),
            new MemoryPersonalIdentityRepository()
        );
    }

    private function passthrough(): Transaction
    {
        return new class implements Transaction {
            public function run(callable $callback): mixed
            {
                return $callback();
            }
        };
    }

    /**
     * @return list<string>
     */
    private function actions(MemoryAuditLog $audit, int $personId): array
    {
        return array_map(static fn (AuditEvent $event): string => $event->action(), $audit->forObject('person', $personId));
    }

    private function today(): AssociationDate
    {
        return AssociationDate::fromIso(self::TODAY);
    }
}
