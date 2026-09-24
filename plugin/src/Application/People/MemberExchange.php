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
        $periodsByPerson = [];

        foreach ($this->memberships->all() as $period) {
            $periodsByPerson[$period->personId()][] = $period;
        }

        $people = $this->people->all();
        usort($people, static fn (Person $left, Person $right): int => ((int) $left->id()) <=> ((int) $right->id()));

        foreach ($people as $person) {
            $personId = $person->id();

            if ($personId === null) {
                continue;
            }

            $periods = $periodsByPerson[$personId] ?? [];
            usort($periods, static function (MembershipPeriod $left, MembershipPeriod $right): int {
                $byDate = $left->startedOn()->iso() <=> $right->startedOn()->iso();

                return $byDate !== 0 ? $byDate : $left->number() <=> $right->number();
            });

            foreach ($periods as $period) {
                fputcsv($handle, [
                    $this->cell($person->firstName()),
                    $this->cell($person->lastName()),
                    $this->cell($person->email()),
                    $person->status()->value,
                    $this->cell($period->number()),
                    $this->cell($period->type()),
                    $period->status()->value,
                    $period->startedOn()->iso(),
                    $period->endedOn()?->iso() ?? '',
                ], ';', '"', '\\');
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

    public function import(string $csv): MemberImportResult
    {
        $this->require(Capabilities::EDIT_MEMBERS);
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

        if ($this->memberships->findByNumber($number) instanceof MembershipPeriod) {
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

        $period = new MembershipPeriod(
            null,
            $personId,
            $number,
            $row['membership_type'],
            $membershipStatus,
            $startedOn,
            $endedOn
        );
        $this->ledger->add($this->memberships->forPerson($personId), $period);
        $this->memberships->add($period);

        return 'created';
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
