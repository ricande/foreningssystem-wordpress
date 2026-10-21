<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use Foreningssystem\Application\Account\MemberAccountProvisioner;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Membership\ParticipantAdmission;
use Foreningssystem\Domain\Organization\Organization;
use Foreningssystem\Domain\Organization\OrganizationNumber;
use Foreningssystem\Domain\Organization\OrganizationRepository;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;
use Foreningssystem\Domain\Person\PersonStatus;
use InvalidArgumentException;

final class MemberExchange
{
    private const COLUMNS = [
        'first_name',
        'last_name',
        'email',
        'person_status',
        'membership_number',
        'membership_type',
        'membership_status',
        'started_on',
        'ended_on',
    ];

    /**
     * Participant rows in the structured file.
     * person_status is the eleventh column, added later. A row without it is a known person.
     */
    private const PARTICIPANT_STATUS = 10;

    /**
     * RFC 4180 quoting: a quote is doubled and a backslash is plain text.
     * With PHP's backslash escape a value ending in a backslash swallows the closing quote.
     */
    private const ESCAPE = '';

    /** Spreadsheet formula starts (OWASP CSV injection), optionally behind apostrophes. */
    private const FORMULA = "/^'*[=+\\-@\t\r]/";

    private const ROW_NOT_SAVED = 'The row could not be saved. No part of it was kept.';
    private const EMAIL_NAME_CONFLICT = 'Not imported. This email belongs to a person with another name or birth date in the register.'
        . ' Correct the email or the name in the file, or change the person in the register first.';
    private const BUSY = 'Another change to the member register was being saved, so the row was not imported. Import the file again to add it.';

    /** @var list<int> */
    private array $provisionPeople = [];

    /** @var array<int, int> person id => line */
    private array $deceasedPeople = [];

    public function __construct(
        private readonly PersonRepository $people,
        private readonly MembershipRepository $memberships,
        private readonly MembershipLedger $ledger,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
        private readonly OrganizationRepository $organizations,
        private readonly MemberRegisterLock $lock,
        private readonly ?MemberAccountProvisioner $accounts = null,
        private readonly ?AssociationDate $provisionOn = null,
    ) {
    }

    public function export(): string
    {
        $this->require(Capabilities::EXPORT_MEMBERS);
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('The member export could not be opened.');
        }

        fputcsv($handle, self::COLUMNS, ';', '"', self::ESCAPE);
        $accounts = [];

        foreach ($this->memberships->allMemberships() as $membership) {
            if ($membership->id() !== null) {
                $accounts[$membership->id()] = $membership;
            }
        }

        $lines = [];

        foreach ($this->memberships->allParticipants() as $participant) {
            if (! $participant->role()->countsAsMember()) {
                continue;
            }

            $person = $this->people->find($participant->personId());
            $account = $accounts[$participant->membershipId()] ?? null;

            if (! $person instanceof Person || $account === null) {
                continue;
            }

            foreach ($this->memberships->periodsForMembership($participant->membershipId()) as $period) {
                if (\Foreningssystem\Domain\Membership\MemberCoverage::participationOverlapsPeriod($participant, $period)) {
                    $lines[] = [$person, $account, $period];
                }
            }
        }

        usort($lines, static function (array $left, array $right): int {
            $byPerson = ((int) $left[0]->id()) <=> ((int) $right[0]->id());

            if ($byPerson !== 0) {
                return $byPerson;
            }

            $byDate = $left[2]->startedOn()->iso() <=> $right[2]->startedOn()->iso();

            return $byDate !== 0 ? $byDate : $left[1]->number() <=> $right[1]->number();
        });

        foreach ($lines as [$person, $account, $period]) {
            fputcsv($handle, [
                $this->cell($person->firstName()),
                $this->cell($person->lastName()),
                $this->cell($person->email()),
                $person->status()->value,
                $this->cell($account->number()),
                $this->cell($period->historicalClass() !== '' ? $period->historicalClass() : $account->kind()->value),
                $period->status()->value,
                $period->startedOn()->iso(),
                $period->endedOn()?->iso() ?? '',
            ], ';', '"', self::ESCAPE);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            throw new \RuntimeException('The member export could not be read.');
        }

        return "\xEF\xBB\xBF" . $csv;
    }

    public function import(string $csv): MemberImportResult
    {
        $this->require(Capabilities::EDIT_MEMBERS);
        $plain = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;

        if (str_starts_with($plain, '# foreningsplugin-members 2')) {
            return $this->importStructure($plain);
        }

        $rows = $this->rows($csv);
        $created = 0;
        $skipped = 0;
        $errors = [];
        $this->provisionPeople = [];
        $createdNumbers = [];

        foreach ($rows as $line => $row) {
            if (isset($createdNumbers[$row['membership_number']])) {
                $errors[] = 'Line ' . $line . ': Not imported. Membership ' . $row['membership_number']
                    . ' was created from an earlier line. This file brings in one period and one member per membership.'
                    . ' Use the structured member file for later periods and family members.';

                continue;
            }

            try {
                $outcome = $this->writeRow(fn (): string => $this->importRow($row));

                if ($outcome === 'skipped') {
                    $skipped++;
                } else {
                    $created++;
                    $createdNumbers[$row['membership_number']] = true;
                }
            } catch (MembershipRuleException | InvalidArgumentException $error) {
                $errors[] = 'Line ' . $line . ': ' . $error->getMessage();
            } catch (MemberRegisterBusy) {
                $errors[] = 'Line ' . $line . ': ' . self::BUSY;
            } catch (\RuntimeException) {
                $errors[] = 'Line ' . $line . ': ' . self::ROW_NOT_SAVED;
            }
        }

        $this->provisionRemembered();

        return new MemberImportResult($created, $skipped, $errors);
    }

    /**
     * @param array<string, string> $row
     */
    private function importRow(array $row): string
    {
        $number = $row['membership_number'];
        $blankPeriod = $number === ''
            && $row['membership_type'] === ''
            && $row['membership_status'] === ''
            && $row['started_on'] === ''
            && $row['ended_on'] === '';

        if ($blankPeriod) {
            return 'skipped';
        }

        if ($number === '' || $row['membership_type'] === '') {
            throw new InvalidArgumentException('A membership needs a number and a type.');
        }

        if ($this->memberships->findMembershipByNumber($number) instanceof \Foreningssystem\Domain\Membership\Membership) {
            return 'skipped';
        }

        $personStatus = PersonStatus::tryFrom($row['person_status']);
        $membershipStatus = MembershipStatus::tryFrom($row['membership_status']);

        if (! $personStatus instanceof PersonStatus || ! $membershipStatus instanceof MembershipStatus) {
            throw new InvalidArgumentException('Unknown person or membership status.');
        }

        $startedOn = AssociationDate::fromIso($row['started_on']);
        $endedOn = $row['ended_on'] === '' ? null : AssociationDate::fromIso($row['ended_on']);

        if ($membershipStatus === MembershipStatus::Ended && ! $endedOn instanceof AssociationDate) {
            throw new InvalidArgumentException('An ended membership needs an end date.');
        }

        if ($membershipStatus !== MembershipStatus::Ended && $endedOn instanceof AssociationDate) {
            throw new InvalidArgumentException('An open membership has no end date.');
        }

        if ($personStatus === PersonStatus::Deceased && $membershipStatus !== MembershipStatus::Ended) {
            throw new InvalidArgumentException('A deceased person cannot have an open membership.');
        }

        $person = $this->personFor($row['email'], $row['first_name'], $row['last_name'], '', null);

        if ($person instanceof Person && $person->status() === PersonStatus::Deceased && $membershipStatus !== MembershipStatus::Ended) {
            throw new InvalidArgumentException('A deceased person cannot have an open membership.');
        }

        if (! $person instanceof Person) {
            $person = $this->people->add(new Person(
                null,
                $row['first_name'],
                $row['last_name'],
                $row['email'],
                $personStatus,
                null
            ));
        }

        $personId = $person->id();

        if ($personId === null) {
            throw new \RuntimeException('The person was not saved.');
        }

        $kind = \Foreningssystem\Domain\Membership\MembershipKind::knownSlug($row['membership_type'])
            ? \Foreningssystem\Domain\Membership\MembershipKind::fromSlug($row['membership_type'])
            : \Foreningssystem\Domain\Membership\MembershipKind::Ordinary;

        if ($kind === \Foreningssystem\Domain\Membership\MembershipKind::Company) {
            throw new InvalidArgumentException('Company memberships use the structured member file.');
        }

        $membership = $this->memberships->addMembership(new \Foreningssystem\Domain\Membership\Membership(null, $number, $kind, null));
        $membershipId = $membership->id();

        if ($membershipId === null) {
            throw new \RuntimeException('The membership was not saved.');
        }

        $incoming = new \Foreningssystem\Domain\Membership\MembershipParticipant(
            null,
            $membershipId,
            $personId,
            \Foreningssystem\Domain\Membership\ParticipantRole::Member,
            true,
            $startedOn,
            null
        );
        $period = new MembershipPeriod(null, $membershipId, $membershipStatus, $startedOn, $endedOn, $row['membership_type']);
        ParticipantAdmission::assertNoOverlap(
            $incoming,
            [],
            [$period],
            $this->memberships->allParticipants(),
            $this->memberships->all()
        );

        $this->memberships->addParticipant($incoming);

        $this->ledger->add([], $period);
        $this->memberships->add($period);
        $this->rememberAccountPerson($personId);

        return 'created';
    }

    public function exportStructure(): string
    {
        $this->require(Capabilities::EXPORT_MEMBERS);
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('The member export could not be opened.');
        }

        fwrite($handle, "# foreningsplugin-members 2\n");
        $organizations = [];

        foreach ($this->organizations->all() as $organization) {
            $number = $organization->number()?->canonical() ?? '';
            $this->writeRecord($handle, [
                'organization',
                $this->cell($number),
                $this->cell($organization->name()),
                $this->cell($organization->email()),
                $this->cell($organization->postalAddress()),
            ]);

            if ($organization->id() !== null) {
                $organizations[$organization->id()] = $organization;
            }
        }

        foreach ($this->memberships->allMemberships() as $membership) {
            $organization = $membership->organizationId() === null ? null : ($organizations[$membership->organizationId()] ?? null);
            $this->writeRecord($handle, [
                'membership',
                $this->cell($membership->number()),
                $membership->kind()->value,
                $this->cell($organization?->number()?->canonical() ?? ''),
            ]);

            foreach ($this->memberships->periodsForMembership((int) $membership->id()) as $period) {
                $this->writeRecord($handle, [
                    'period',
                    $this->cell($membership->number()),
                    $period->status()->value,
                    $period->startedOn()->iso(),
                    $period->endedOn()?->iso() ?? '',
                    $this->cell($period->historicalClass()),
                ]);
            }

            foreach ($this->memberships->participantsForMembership((int) $membership->id()) as $participant) {
                $person = $this->people->find($participant->personId());

                if (! $person instanceof Person) {
                    continue;
                }

                $this->writeRecord($handle, [
                    'participant',
                    $this->cell($membership->number()),
                    $this->cell($person->firstName()),
                    $this->cell($person->lastName()),
                    $this->cell($person->email()),
                    $person->birthDate()?->iso() ?? '',
                    $participant->role()->value,
                    $participant->isPrimary() ? '1' : '0',
                    $participant->startedOn()->iso(),
                    $participant->endedOn()?->iso() ?? '',
                    $person->status()->value,
                ]);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            throw new \RuntimeException('The member export could not be read.');
        }

        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * @param resource     $handle
     * @param list<string> $cells
     */
    private function writeRecord($handle, array $cells): void
    {
        if (fputcsv($handle, $cells, ';', '"', self::ESCAPE, "\n") === false) {
            throw new \RuntimeException('The member export could not be written.');
        }
    }

    private function importStructure(string $csv): MemberImportResult
    {
        if (! mb_check_encoding($csv, 'UTF-8')) {
            throw new InvalidArgumentException('The file must be UTF-8.');
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        $this->provisionPeople = [];
        $this->deceasedPeople = [];
        $records = ['organization' => [], 'membership' => [], 'participant' => [], 'period' => []];
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('The member import could not be opened.');
        }

        fwrite($handle, $csv);
        rewind($handle);
        $next = 1;

        // fgetcsv reads bytes, so a UTF-8 letter is never a line break. A quoted cell may span lines.
        while (($cells = fgetcsv($handle, 0, ';', '"', self::ESCAPE)) !== false) {
            $line = $next;
            $position = ftell($handle);
            $next = 1 + substr_count($csv, "\n", 0, $position === false ? strlen($csv) : $position);
            $type = $this->cellIn((string) ($cells[0] ?? ''));

            if (($type === '' && count($cells) === 1) || str_starts_with($type, '#')) {
                continue;
            }

            if ($type === 'identity' || $type === 'personal_identity') {
                throw new InvalidArgumentException('Personal identity numbers are not imported from the member file.');
            }

            if (! isset($records[$type])) {
                $errors[] = 'Line ' . $line . ': Unknown record.';

                continue;
            }

            $records[$type][] = ['line' => $line, 'cells' => $cells];
        }

        fclose($handle);

        foreach (['organization', 'membership', 'period', 'participant'] as $type) {
            foreach ($records[$type] as $record) {
                try {
                    $outcome = $this->writeRow(fn (): string => $this->importStructureRow($type, $record['cells'], $record['line']));
                    $outcome === 'skipped' ? $skipped++ : $created++;
                } catch (MembershipRuleException | InvalidArgumentException $error) {
                    $errors[] = 'Line ' . $record['line'] . ': ' . $error->getMessage();
                } catch (MemberRegisterBusy) {
                    $errors[] = 'Line ' . $record['line'] . ': ' . self::BUSY;
                } catch (\RuntimeException) {
                    $errors[] = 'Line ' . $record['line'] . ': ' . self::ROW_NOT_SAVED;
                }
            }
        }

        foreach ($this->deceasedPeople as $personId => $line) {
            try {
                $this->writeRow(function () use ($personId): string {
                    $this->markImportedDeceased($personId);

                    return 'updated';
                });
            } catch (MembershipRuleException | InvalidArgumentException $error) {
                $errors[] = 'Line ' . $line . ': ' . $error->getMessage();
            } catch (MemberRegisterBusy) {
                $errors[] = 'Line ' . $line . ': ' . self::BUSY;
            } catch (\RuntimeException) {
                $errors[] = 'Line ' . $line . ': ' . self::ROW_NOT_SAVED;
            }
        }

        $this->deceasedPeople = [];

        $this->provisionRemembered();

        return new MemberImportResult($created, $skipped, $errors);
    }

    /**
     * @param list<string|null> $cells
     */
    private function importStructureRow(string $type, array $cells, int $line): string
    {
        $value = fn (int $index): string => $this->cellIn((string) ($cells[$index] ?? ''));

        if ($type === 'organization') {
            $number = $value(1) === '' ? null : OrganizationNumber::parse($value(1));

            if ($number instanceof OrganizationNumber && $this->organizations->findByNumber($number->canonical()) instanceof Organization) {
                return 'skipped';
            }

            $this->organizations->add(new Organization(null, $value(2), $number, $value(3), $value(4)));

            return 'created';
        }

        if ($type === 'membership') {
            if ($this->memberships->findMembershipByNumber($value(1)) instanceof \Foreningssystem\Domain\Membership\Membership) {
                return 'skipped';
            }

            $kind = \Foreningssystem\Domain\Membership\MembershipKind::fromSlug($value(2));
            $organizationId = null;

            if ($kind === \Foreningssystem\Domain\Membership\MembershipKind::Company) {
                $organization = $this->organizations->findByNumber(OrganizationNumber::parse($value(3))->canonical());

                if (! $organization instanceof Organization || $organization->id() === null) {
                    throw new InvalidArgumentException('The company membership needs an organization.');
                }

                $organizationId = $organization->id();
            }

            $this->memberships->addMembership(new \Foreningssystem\Domain\Membership\Membership(null, $value(1), $kind, $organizationId));

            return 'created';
        }

        $membership = $this->memberships->findMembershipByNumber($value(1));

        if (! $membership instanceof \Foreningssystem\Domain\Membership\Membership || $membership->id() === null) {
            throw new InvalidArgumentException('The membership number was not found.');
        }

        if ($type === 'participant') {
            $email = $value(4);
            $statusCell = $value(self::PARTICIPANT_STATUS);
            $personStatus = $statusCell === '' ? PersonStatus::Known : PersonStatus::tryFrom($statusCell);

            if (! $personStatus instanceof PersonStatus) {
                throw new InvalidArgumentException('Unknown person status.');
            }

            $startedOn = $this->participantStart($cells, $membership->id());
            $endedOn = $value(9) === '' ? null : AssociationDate::fromIso($value(9));
            $role = \Foreningssystem\Domain\Membership\ParticipantRole::tryFrom($value(6));

            if (! $role instanceof \Foreningssystem\Domain\Membership\ParticipantRole) {
                throw new InvalidArgumentException('Unknown participant role.');
            }

            $person = $email === ''
                ? $this->participantWithoutEmail($membership->id(), $value(2), $value(3), $value(5))
                : $this->personFor($email, $value(2), $value(3), $value(5), $membership->id());
            $newPerson = ! $person instanceof Person;

            if ($newPerson && $personStatus === PersonStatus::Deceased && $role->countsAsMember() && ! $endedOn instanceof AssociationDate) {
                throw new InvalidArgumentException('A deceased person cannot have an open membership.');
            }

            if (! $person instanceof Person) {
                $birth = $value(5) === '' ? null : AssociationDate::fromIso($value(5));
                // A deceased person is stored as known until every row is in, so the ended history passes admission.
                $person = $this->people->add(new Person(null, $value(2), $value(3), $email, PersonStatus::Known, null, $birth));
            }

            $personId = (int) $person->id();

            $participant = new \Foreningssystem\Domain\Membership\MembershipParticipant(
                null,
                $membership->id(),
                $personId,
                $role,
                $value(7) === '1',
                $startedOn,
                $endedOn
            );

            foreach ($this->memberships->participantsForMembership($membership->id()) as $existing) {
                $sameInterval = $existing->personId() === $personId
                    && $existing->startedOn()->iso() === $participant->startedOn()->iso()
                    && $existing->endedOn()?->iso() === $participant->endedOn()?->iso();

                if ($sameInterval) {
                    return 'skipped';
                }
            }

            ParticipantAdmission::assertRole(
                $membership->kind(),
                $role,
                $person->status() === PersonStatus::Deceased,
                $this->memberships->participantsForMembership($membership->id()),
                $personId
            );
            ParticipantAdmission::assertNoOverlap(
                $participant,
                $this->memberships->participantsForMembership($membership->id()),
                $this->memberships->periodsForMembership($membership->id()),
                $this->memberships->allParticipants(),
                $this->memberships->all()
            );

            $this->memberships->addParticipant($participant);

            if ($personStatus === PersonStatus::Deceased && ($newPerson || isset($this->deceasedPeople[$personId]))) {
                $this->deceasedPeople[$personId] ??= $line;
            } elseif ($role->countsAsMember()) {
                $this->rememberAccountPerson($personId);
            }

            return 'created';
        }

        $startedOn = AssociationDate::fromIso($value(3));
        $endedOn = $value(4) === '' ? null : AssociationDate::fromIso($value(4));
        $status = MembershipStatus::tryFrom($value(2));

        if (! $status instanceof MembershipStatus) {
            throw new InvalidArgumentException('Unknown membership status.');
        }
        $period = new MembershipPeriod(null, $membership->id(), $status, $startedOn, $endedOn, $value(5));

        foreach ($this->memberships->periodsForMembership($membership->id()) as $existing) {
            if ($existing->startedOn()->iso() === $startedOn->iso() && $existing->endedOn()?->iso() === $endedOn?->iso()) {
                return 'skipped';
            }

            if ($existing->overlaps($period)) {
                throw new MembershipRuleException('Membership periods cannot overlap.');
            }
        }

        $this->ledger->add([], $period);
        $this->memberships->add($period);

        foreach ($this->memberships->participantsForMembership($membership->id()) as $participant) {
            if ($participant->role()->countsAsMember()) {
                $this->rememberAccountPerson($participant->personId());
            }
        }

        return 'created';
    }

    /**
     * Only a person created by this import is marked. A stored person is not changed by a file.
     */
    private function markImportedDeceased(int $personId): void
    {
        $person = $this->people->find($personId);

        if (! $person instanceof Person || $person->status() === PersonStatus::Deceased) {
            return;
        }

        foreach ($this->memberships->allParticipants() as $participant) {
            if ($participant->personId() === $personId && $participant->role()->countsAsMember() && ! $participant->endedOn() instanceof AssociationDate) {
                throw new InvalidArgumentException('A deceased person cannot have an open membership.');
            }
        }

        $this->people->save($person->markedDeceased());
        $this->provisionPeople = array_values(array_filter(
            $this->provisionPeople,
            static fn (int $remembered): bool => $remembered !== $personId
        ));
    }

    /**
     * Each row holds the register on its own, so officers can still save between the
     * rows of a long file. The row's checks run inside the claim. See ADR-0031.
     *
     * @param callable(): string $row
     */
    private function writeRow(callable $row): string
    {
        return $this->lock->hold(fn (): string => $this->transaction->run($row));
    }

    private function rememberAccountPerson(int $personId): void
    {
        if ($personId > 0) {
            $this->provisionPeople[] = $personId;
        }
    }

    private function provisionRemembered(): void
    {
        if (! $this->accounts instanceof MemberAccountProvisioner || ! $this->provisionOn instanceof AssociationDate) {
            $this->provisionPeople = [];

            return;
        }

        $people = array_values(array_unique($this->provisionPeople));
        $this->provisionPeople = [];

        foreach ($people as $personId) {
            $this->accounts->provision($personId, $this->provisionOn);
        }
    }

    /**
     * The current export always writes the start column.
     * An empty start in that column is rejected.
     * A row that has no start column at all is older compatibility input, and uses the earliest period start.
     *
     * @param list<string|null> $cells
     */
    private function participantStart(array $cells, int $membershipId): AssociationDate
    {
        if (! array_key_exists(8, $cells)) {
            return $this->earliestPeriodStart($membershipId);
        }

        $startedOn = $this->cellIn((string) $cells[8]);

        if ($startedOn === '') {
            throw new InvalidArgumentException('A participant needs a start date.');
        }

        return AssociationDate::fromIso($startedOn);
    }

    private function earliestPeriodStart(int $membershipId): AssociationDate
    {
        $earliest = null;

        foreach ($this->memberships->periodsForMembership($membershipId) as $period) {
            if (! $earliest instanceof AssociationDate || $period->startedOn()->isBefore($earliest)) {
                $earliest = $period->startedOn();
            }
        }

        if (! $earliest instanceof AssociationDate) {
            throw new InvalidArgumentException('A participant needs a start date.');
        }

        return $earliest;
    }

    /**
     * A participant without email is found again only on the same membership, by name and birth date.
     * The same name elsewhere in the register is still a different person.
     */
    private function participantWithoutEmail(int $membershipId, string $firstName, string $lastName, string $birthDate): ?Person
    {
        foreach ($this->memberships->participantsForMembership($membershipId) as $participant) {
            $person = $this->people->find($participant->personId());

            if (
                $person instanceof Person
                && $person->email() === ''
                && $person->firstName() === $firstName
                && $person->lastName() === $lastName
                && ($person->birthDate()?->iso() ?? '') === $birthDate
            ) {
                return $person;
            }
        }

        return null;
    }

    /**
     * Email alone does not decide who a row is about: a mistyped address would put the
     * membership on someone else. A stored person with the email is used only when the
     * name matches, and the birth date too when both sides have one.
     *
     * A different name on the address is a conflict, unless a person with that address is
     * already on the same membership: a family that shares one address. Then the row is a
     * new person. See ADR-0030.
     */
    private function personFor(string $email, string $firstName, string $lastName, string $birthDate, ?int $membershipId): ?Person
    {
        if ($email === '') {
            return null;
        }

        $matches = [];

        foreach ($this->people->all() as $person) {
            if (strtolower($person->email()) === strtolower($email)) {
                $matches[] = $person;
            }
        }

        if ($matches === []) {
            return null;
        }

        $named = array_values(array_filter(
            $matches,
            fn (Person $person): bool => $this->samePerson($person, $firstName, $lastName, $birthDate)
        ));

        if (count($named) > 1) {
            throw new InvalidArgumentException('More than one person has this email and name.');
        }

        if ($named !== []) {
            return $named[0];
        }

        if ($membershipId !== null) {
            foreach ($this->memberships->participantsForMembership($membershipId) as $participant) {
                foreach ($matches as $person) {
                    if ($participant->personId() === $person->id()) {
                        return null;
                    }
                }
            }
        }

        throw new InvalidArgumentException(self::EMAIL_NAME_CONFLICT);
    }

    private function samePerson(Person $person, string $firstName, string $lastName, string $birthDate): bool
    {
        $fold = static fn (string $name): string => mb_strtolower(trim($name), 'UTF-8');

        if ($fold($person->firstName()) !== $fold($firstName) || $fold($person->lastName()) !== $fold($lastName)) {
            return false;
        }

        $stored = $person->birthDate()?->iso();

        return $stored === null || $birthDate === '' || $stored === $birthDate;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function rows(string $csv): array
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }

        if ($csv === '' || ! mb_check_encoding($csv, 'UTF-8')) {
            throw new InvalidArgumentException('The file must be UTF-8.');
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('The member import could not be opened.');
        }

        fwrite($handle, $csv);
        rewind($handle);
        $header = fgetcsv($handle, 0, ';', '"', self::ESCAPE);

        if ($header === false || array_map($this->cellIn(...), $header) !== self::COLUMNS) {
            fclose($handle);

            throw new InvalidArgumentException('The file must use the member columns separated by semicolons.');
        }

        $rows = [];
        $line = 1;

        while (($data = fgetcsv($handle, 0, ';', '"', self::ESCAPE)) !== false) {
            $line++;

            if ($data === [null] || $data === ['']) {
                continue;
            }

            if (count($data) !== count(self::COLUMNS)) {
                $rows[$line] = array_fill_keys(self::COLUMNS, '');
                $rows[$line]['membership_number'] = 'invalid-column-count';
                $rows[$line]['membership_type'] = 'invalid';
                $rows[$line]['membership_status'] = 'invalid';
                $rows[$line]['started_on'] = 'invalid';

                continue;
            }

            $row = [];

            foreach (self::COLUMNS as $index => $column) {
                $row[$column] = $this->cellIn((string) $data[$index]);
            }

            $rows[$line] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function cell(string $value): string
    {
        // A leading apostrophe is doubled too, so a value that really starts with one survives cellIn.
        if (preg_match(self::FORMULA, $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }

    private function cellIn(string $value): string
    {
        $value = trim($value);

        if (str_starts_with($value, "'") && preg_match(self::FORMULA, substr($value, 1)) === 1) {
            return substr($value, 1);
        }

        return $value;
    }

    private function require(string $capability): void
    {
        if (! $this->authorizer->allows($capability)) {
            throw new NotAllowed($capability);
        }
    }
}
