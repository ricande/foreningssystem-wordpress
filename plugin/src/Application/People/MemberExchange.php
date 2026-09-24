<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

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

    public function __construct(
        private readonly PersonRepository $people,
        private readonly MembershipRepository $memberships,
        private readonly MembershipLedger $ledger,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
        private readonly OrganizationRepository $organizations,
    ) {
    }

    public function export(): string
    {
        $this->require(Capabilities::EXPORT_MEMBERS);
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('The member export could not be opened.');
        }

        fputcsv($handle, self::COLUMNS, ';', '"', '\\');
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
            ], ';', '"', '\\');
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

        foreach ($rows as $line => $row) {
            try {
                $outcome = $this->transaction->run(fn (): string => $this->importRow($row));

                if ($outcome === 'skipped') {
                    $skipped++;
                } else {
                    $created++;
                }
            } catch (MembershipRuleException | InvalidArgumentException $error) {
                $errors[] = 'Line ' . $line . ': ' . $error->getMessage();
            }
        }

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

        $person = $this->personFor($row['email']);

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

        return 'created';
    }

    public function exportStructure(): string
    {
        $this->require(Capabilities::EXPORT_MEMBERS);
        $lines = ['# foreningsplugin-members 2'];
        $organizations = [];

        foreach ($this->organizations->all() as $organization) {
            $number = $organization->number()?->canonical() ?? '';
            $lines[] = implode(';', [
                'organization',
                $this->cell($number),
                $this->cell($organization->name()),
                $this->cell($organization->email()),
                $this->cell(str_replace(["\n", "\r", ';'], ' ', $organization->postalAddress())),
            ]);

            if ($organization->id() !== null) {
                $organizations[$organization->id()] = $organization;
            }
        }

        foreach ($this->memberships->allMemberships() as $membership) {
            $organization = $membership->organizationId() === null ? null : ($organizations[$membership->organizationId()] ?? null);
            $lines[] = implode(';', [
                'membership',
                $this->cell($membership->number()),
                $membership->kind()->value,
                $this->cell($organization?->number()?->canonical() ?? ''),
            ]);

            foreach ($this->memberships->periodsForMembership((int) $membership->id()) as $period) {
                $lines[] = implode(';', [
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

                $lines[] = implode(';', [
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
                ]);
            }
        }

        return "\xEF\xBB\xBF" . implode("\n", $lines) . "\n";
    }

    private function importStructure(string $csv): MemberImportResult
    {
        if (! mb_check_encoding($csv, 'UTF-8')) {
            throw new InvalidArgumentException('The file must be UTF-8.');
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        $records = ['organization' => [], 'membership' => [], 'participant' => [], 'period' => []];
        $line = 0;

        foreach (preg_split('/\R/', $csv) ?: [] as $raw) {
            $line++;
            $raw = trim($raw);

            if ($raw === '' || str_starts_with($raw, '#')) {
                continue;
            }

            $cells = str_getcsv($raw, ';', '"', '\\');
            $type = $this->cellIn((string) ($cells[0] ?? ''));

            if ($type === 'identity' || $type === 'personal_identity') {
                throw new InvalidArgumentException('Personal identity numbers are not imported from the member file.');
            }

            if (! isset($records[$type])) {
                $errors[] = 'Line ' . $line . ': Unknown record.';

                continue;
            }

            $records[$type][] = ['line' => $line, 'cells' => $cells];
        }

        foreach (['organization', 'membership', 'period', 'participant'] as $type) {
            foreach ($records[$type] as $record) {
                try {
                    $outcome = $this->transaction->run(fn (): string => $this->importStructureRow($type, $record['cells']));
                    $outcome === 'skipped' ? $skipped++ : $created++;
                } catch (MembershipRuleException | InvalidArgumentException $error) {
                    $errors[] = 'Line ' . $record['line'] . ': ' . $error->getMessage();
                }
            }
        }

        return new MemberImportResult($created, $skipped, $errors);
    }

    /**
     * @param list<string|null> $cells
     */
    private function importStructureRow(string $type, array $cells): string
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
            $person = $this->personFor($email);

            if (! $person instanceof Person) {
                $birth = $value(5) === '' ? null : AssociationDate::fromIso($value(5));
                $person = $this->people->add(new Person(null, $value(2), $value(3), $email, PersonStatus::Known, null, $birth));
            }

            $personId = (int) $person->id();
            $startedOn = $this->participantStart($cells, $membership->id());
            $endedOn = $value(9) === '' ? null : AssociationDate::fromIso($value(9));
            $role = \Foreningssystem\Domain\Membership\ParticipantRole::tryFrom($value(6));

            if (! $role instanceof \Foreningssystem\Domain\Membership\ParticipantRole) {
                throw new InvalidArgumentException('Unknown participant role.');
            }

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

        return 'created';
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

    private function personFor(string $email): ?Person
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

        if (count($matches) > 1) {
            throw new InvalidArgumentException('More than one person has this email.');
        }

        return $matches[0] ?? null;
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
        $header = fgetcsv($handle, 0, ';', '"', '\\');

        if ($header === false || array_map($this->cellIn(...), $header) !== self::COLUMNS) {
            fclose($handle);

            throw new InvalidArgumentException('The file must use the member columns separated by semicolons.');
        }

        $rows = [];
        $line = 1;

        while (($data = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
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
        if ($value !== '' && preg_match('/^[=+\-@]/', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }

    private function cellIn(string $value): string
    {
        $value = trim($value);

        if ($value !== '' && str_starts_with($value, "'") && preg_match('/^[=+\-@]/', substr($value, 1)) === 1) {
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
