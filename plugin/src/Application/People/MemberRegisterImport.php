<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\Membership;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRepository;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Membership\ParticipantAdmission;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Domain\Membership\MembershipParticipant;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;
use Foreningssystem\Domain\Person\PersonStatus;
use Throwable;

/**
 * Imports a foreign member spreadsheet into the existing person and membership model.
 *
 * A shared email, a repeated email in the file, or an existing membership number is skipped
 * and explained. People are not merged, and no WordPress user is linked. Preview only reads.
 */
final class MemberRegisterImport
{
    private const NAME_MAX = 100;

    private const EMAIL_MAX = 190;

    private const NUMBER_MAX = 50;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly MembershipRepository $memberships,
        private readonly MembershipLedger $ledger,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
    ) {
    }

    /**
     * @param array<int, string> $mapping
     */
    public function plan(MemberCsvTable $table, array $mapping, AssociationDate $today): MemberRegisterPlan
    {
        $this->require(Capabilities::EDIT_MEMBERS);

        return $this->assess($table, $mapping, $today);
    }

    /**
     * @param array<int, string> $mapping
     */
    public function commit(MemberCsvTable $table, array $mapping, AssociationDate $today): MemberRegisterResult
    {
        $this->require(Capabilities::EDIT_MEMBERS);
        $plan = $this->assess($table, $mapping, $today);

        if ($plan->blockers !== []) {
            return new MemberRegisterResult(0, 0, 0, []);
        }

        $imported = 0;
        $duplicates = $plan->duplicates();
        $failed = $plan->errors();
        $issues = $plan->issues;

        foreach ($plan->readyRows as $row) {
            try {
                $this->transaction->run(function () use ($row): void {
                    $this->write($row);
                });
                $imported++;
            } catch (MemberRegisterDuplicate) {
                $duplicates++;
                $issues[] = new MemberRegisterIssue($row['line'], 'duplicate', 'number_exists');
            } catch (MembershipRuleException $error) {
                if ($error->getMessage() === 'Membership number is already used.') {
                    $duplicates++;
                    $issues[] = new MemberRegisterIssue($row['line'], 'duplicate', 'number_exists');
                    continue;
                }

                $failed++;
                $issues[] = new MemberRegisterIssue($row['line'], 'error', 'save_failed');
            } catch (Throwable) {
                $failed++;
                $issues[] = new MemberRegisterIssue($row['line'], 'error', 'save_failed');
            }
        }

        return new MemberRegisterResult($imported, $duplicates, $failed, $issues);
    }

    /**
     * @param array<int, string> $mapping
     */
    private function assess(MemberCsvTable $table, array $mapping, AssociationDate $today): MemberRegisterPlan
    {
        $fields = $this->fields($mapping);
        $notices = $this->notices($table, $fields);
        $blockers = $this->blockers($fields);

        if ($blockers !== []) {
            return new MemberRegisterPlan(count($table->rows), $blockers, $notices, [], []);
        }

        $knownEmails = [];

        foreach ($this->people->all() as $person) {
            $email = strtolower($person->email());

            if ($email !== '') {
                $knownEmails[$email] = true;
            }
        }

        $candidates = [];
        $issues = [];
        $statusDefault = false;
        $personDefault = false;

        foreach ($table->rows as $row) {
            $checked = $this->candidate($row, $fields, $today);

            if ($checked['issue'] instanceof MemberRegisterIssue) {
                $issues[] = $checked['issue'];
                continue;
            }

            if ($checked['status_default']) {
                $statusDefault = true;
            }

            if ($checked['person_default']) {
                $personDefault = true;
            }

            $candidates[] = $checked['row'];
        }

        if ($statusDefault) {
            $notices[] = ['code' => 'status_defaults_active', 'header' => ''];
        }

        if ($personDefault) {
            $notices[] = ['code' => 'person_defaults_known', 'header' => ''];
        }

        $ready = $this->withoutDuplicates($candidates, $knownEmails, $issues);

        return new MemberRegisterPlan(count($table->rows), [], $notices, $issues, $ready);
    }

    /**
     * @param array<int, string> $mapping
     * @return array<int, string>
     */
    private function fields(array $mapping): array
    {
        $fields = [];

        foreach ($mapping as $index => $field) {
            if (! is_string($field) || ! MemberCsvColumns::knownField($field)) {
                continue;
            }

            $fields[(int) $index] = $field;
        }

        return $fields;
    }

    /**
     * @param array<int, string> $fields
     * @return list<array{code: string, header: string}>
     */
    private function notices(MemberCsvTable $table, array $fields): array
    {
        $notices = [];
        $used = [];

        foreach ($fields as $field) {
            if (isset($used[$field])) {
                continue;
            }

            $used[$field] = true;
        }

        foreach ($table->headers as $index => $header) {
            if (isset($fields[$index])) {
                continue;
            }

            $inspection = MemberCsvColumns::inspect($header);

            if ($inspection['notice'] === 'identity') {
                $notices[] = ['code' => 'ignored_identity', 'header' => ''];
            } elseif ($inspection['notice'] === 'unsupported') {
                $notices[] = ['code' => 'ignored_column', 'header' => $header];
            }
        }

        return $notices;
    }

    /**
     * @param array<int, string> $fields
     * @return list<string>
     */
    private function blockers(array $fields): array
    {
        $blockers = [];
        $counts = array_count_values($fields);

        foreach (MemberCsvColumns::REQUIRED as $required) {
            if (! $this->mapped($fields, $required)) {
                $blockers[] = 'map_' . $required;
            }
        }

        foreach ($counts as $field => $count) {
            if ($count > 1) {
                $blockers[] = 'twice_' . $field;
            }
        }

        return $blockers;
    }

    /**
     * @param array<int, string> $fields
     */
    private function mapped(array $fields, string $field): bool
    {
        return in_array($field, $fields, true);
    }

    /**
     * @param array{line: int, cells: list<string>, oversize: bool} $row
     * @param array<int, string> $fields
     * @return array{issue: ?MemberRegisterIssue, row: array{line: int, first_name: string, last_name: string, email: string, birth_date: string, person_status: string, membership_number: string, membership_type: string, membership_status: string, started_on: string, ended_on: string}, status_default: bool, person_default: bool}
     */
    private function candidate(array $row, array $fields, AssociationDate $today): array
    {
        $empty = [
            'line' => $row['line'],
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'birth_date' => '',
            'person_status' => '',
            'membership_number' => '',
            'membership_type' => '',
            'membership_status' => '',
            'started_on' => '',
            'ended_on' => '',
        ];
        $failed = static fn (string $code): array => [
            'issue' => new MemberRegisterIssue($row['line'], 'error', $code),
            'row' => $empty,
            'status_default' => false,
            'person_default' => false,
        ];

        if ($row['oversize']) {
            return $failed('value_too_long');
        }

        $values = $empty;

        foreach ($fields as $index => $field) {
            $values[$field] = $row['cells'][$index] ?? '';
        }

        if (strlen($values['first_name']) > self::NAME_MAX || strlen($values['last_name']) > self::NAME_MAX || strlen($values['email']) > self::EMAIL_MAX || strlen($values['membership_number']) > self::NUMBER_MAX) {
            return $failed('value_too_long');
        }

        if ($values['first_name'] === '' || $values['last_name'] === '') {
            return $failed('missing_name');
        }

        if ($values['membership_number'] === '' || $values['membership_type'] === '') {
            return $failed('needs_number_and_type');
        }

        if ($values['email'] !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            return $failed('invalid_email');
        }

        $kind = $this->kind($values['membership_type']);

        if ($kind === null) {
            return $failed('unknown_type');
        }

        if ($kind === MembershipKind::Company) {
            return $failed('company');
        }

        $personStatus = $this->personStatus($values['person_status']);
        $personDefault = $values['person_status'] === '';

        if (! $personStatus instanceof PersonStatus) {
            return $failed('unknown_status');
        }

        $membershipStatus = $this->membershipStatus($values['membership_status']);
        $statusDefault = $values['membership_status'] === '';

        if (! $membershipStatus instanceof MembershipStatus) {
            return $failed('unknown_status');
        }

        $started = $this->date($values['started_on']);

        if (! $started instanceof AssociationDate) {
            return $failed('invalid_date');
        }

        $ended = null;

        if ($values['ended_on'] !== '') {
            $ended = $this->date($values['ended_on']);

            if (! $ended instanceof AssociationDate) {
                return $failed('invalid_date');
            }
        }

        if ($membershipStatus === MembershipStatus::Ended && ! $ended instanceof AssociationDate) {
            return $failed('ended_needs_date');
        }

        if ($membershipStatus !== MembershipStatus::Ended && $ended instanceof AssociationDate) {
            return $failed('open_has_end');
        }

        if ($ended instanceof AssociationDate && $ended->isBefore($started)) {
            return $failed('ends_before_start');
        }

        if ($personStatus === PersonStatus::Deceased && $membershipStatus !== MembershipStatus::Ended) {
            return $failed('deceased_open');
        }

        $birth = null;

        if ($values['birth_date'] !== '') {
            $birth = $this->date($values['birth_date']);

            if (! $birth instanceof AssociationDate) {
                return $failed('invalid_date');
            }

            if ($birth->isAfter($today)) {
                return $failed('future_birth');
            }
        }

        if ($kind === MembershipKind::Youth && ! $birth instanceof AssociationDate) {
            return $failed('youth_birth');
        }

        $values['person_status'] = $personStatus->value;
        $values['membership_type'] = $kind->value;
        $values['membership_status'] = $membershipStatus->value;
        $values['started_on'] = $started->iso();
        $values['ended_on'] = $ended instanceof AssociationDate ? $ended->iso() : '';
        $values['birth_date'] = $birth instanceof AssociationDate ? $birth->iso() : '';

        return [
            'issue' => null,
            'row' => $values,
            'status_default' => $statusDefault,
            'person_default' => $personDefault,
        ];
    }

    /**
     * @param list<array{line: int, first_name: string, last_name: string, email: string, birth_date: string, person_status: string, membership_number: string, membership_type: string, membership_status: string, started_on: string, ended_on: string}> $candidates
     * @param array<string, bool> $knownEmails
     * @param list<MemberRegisterIssue> $issues
     * @return list<array{line: int, first_name: string, last_name: string, email: string, birth_date: string, person_status: string, membership_number: string, membership_type: string, membership_status: string, started_on: string, ended_on: string}>
     */
    private function withoutDuplicates(array $candidates, array $knownEmails, array &$issues): array
    {
        $seenNumbers = [];
        $afterNumbers = [];

        foreach ($candidates as $row) {
            $number = strtolower($row['membership_number']);

            if ($this->memberships->findMembershipByNumber($row['membership_number']) instanceof Membership) {
                $issues[] = new MemberRegisterIssue($row['line'], 'duplicate', 'number_exists');
                continue;
            }

            if (isset($seenNumbers[$number])) {
                $issues[] = new MemberRegisterIssue($row['line'], 'duplicate', 'number_repeated');
                continue;
            }

            $seenNumbers[$number] = true;
            $afterNumbers[] = $row;
        }

        $emailCounts = [];

        foreach ($afterNumbers as $row) {
            $email = strtolower($row['email']);

            if ($email === '') {
                continue;
            }

            $emailCounts[$email] = ($emailCounts[$email] ?? 0) + 1;
        }

        $ready = [];

        foreach ($afterNumbers as $row) {
            $email = strtolower($row['email']);

            if ($email !== '' && isset($knownEmails[$email])) {
                $issues[] = new MemberRegisterIssue($row['line'], 'duplicate', 'email_exists');
                continue;
            }

            if ($email !== '' && ($emailCounts[$email] ?? 0) > 1) {
                $issues[] = new MemberRegisterIssue($row['line'], 'duplicate', 'email_repeated');
                continue;
            }

            $ready[] = $row;
        }

        return $ready;
    }

    /**
     * @param array{line: int, first_name: string, last_name: string, email: string, birth_date: string, person_status: string, membership_number: string, membership_type: string, membership_status: string, started_on: string, ended_on: string} $row
     */
    private function write(array $row): void
    {
        if ($this->memberships->findMembershipByNumber($row['membership_number']) instanceof Membership) {
            throw new MemberRegisterDuplicate('number');
        }

        $startedOn = AssociationDate::fromIso($row['started_on']);
        $endedOn = $row['ended_on'] === '' ? null : AssociationDate::fromIso($row['ended_on']);
        $birthDate = $row['birth_date'] === '' ? null : AssociationDate::fromIso($row['birth_date']);
        $person = $this->people->add(new Person(
            null,
            $row['first_name'],
            $row['last_name'],
            $row['email'],
            PersonStatus::from($row['person_status']),
            null,
            $birthDate
        ));
        $personId = $person->id();

        if ($personId === null) {
            throw new \RuntimeException('The person was not saved.');
        }

        $kind = MembershipKind::from($row['membership_type']);
        $membership = $this->memberships->addMembership(new Membership(null, $row['membership_number'], $kind, null));
        $membershipId = $membership->id();

        if ($membershipId === null) {
            throw new \RuntimeException('The membership was not saved.');
        }

        $status = MembershipStatus::from($row['membership_status']);
        $incoming = new MembershipParticipant(
            null,
            $membershipId,
            $personId,
            ParticipantRole::Member,
            true,
            $startedOn,
            null
        );
        $period = new MembershipPeriod(null, $membershipId, $status, $startedOn, $endedOn, $kind->value);
        ParticipantAdmission::assertNoOverlap(
            $incoming,
            [],
            [$period],
            $this->memberships->allParticipants(),
            $this->memberships->all()
        );
        $this->ledger->add([], $period);
        $this->memberships->addParticipant($incoming);
        $this->memberships->add($period);
    }

    private function kind(string $value): ?MembershipKind
    {
        $folded = mb_strtolower(trim($value), 'UTF-8');

        return match ($folded) {
            'ordinary', 'ordinarie' => MembershipKind::Ordinary,
            'youth', 'ungdom' => MembershipKind::Youth,
            'family', 'familj' => MembershipKind::Family,
            'company', 'företag', 'foretag' => MembershipKind::Company,
            default => null,
        };
    }

    private function membershipStatus(string $value): ?MembershipStatus
    {
        if (trim($value) === '') {
            return MembershipStatus::Active;
        }

        $folded = mb_strtolower(trim($value), 'UTF-8');

        return match ($folded) {
            'active', 'aktiv' => MembershipStatus::Active,
            'pending', 'väntande', 'vantande' => MembershipStatus::Pending,
            'dormant', 'vilande' => MembershipStatus::Dormant,
            'ended', 'avslutad' => MembershipStatus::Ended,
            default => null,
        };
    }

    private function personStatus(string $value): ?PersonStatus
    {
        if (trim($value) === '') {
            return PersonStatus::Known;
        }

        $folded = mb_strtolower(trim($value), 'UTF-8');

        return match ($folded) {
            'known', 'känd', 'kand' => PersonStatus::Known,
            'deceased', 'avliden' => PersonStatus::Deceased,
            default => null,
        };
    }

    private function date(string $value): ?AssociationDate
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            try {
                return AssociationDate::fromIso($value);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $parts) === 1) {
            try {
                return AssociationDate::fromIso($parts[3] . '-' . $parts[2] . '-' . $parts[1]);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return null;
    }

    private function require(string $capability): void
    {
        if (! $this->authorizer->allows($capability)) {
            throw new NotAllowed($capability);
        }
    }
}
