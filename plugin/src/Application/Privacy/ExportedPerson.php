<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Privacy;

use Foreningssystem\Domain\Person\ContactDetails;

final class ExportedPerson
{
    /**
     * @param list<ExportedMembership> $memberships
     * @param list<ExportedAssignment> $assignments
     * @param list<ExportedAttendance> $attendance
     * @param list<string> $guardianNotes
     * @param list<array{on: string, part: string}> $contactChanges
     */
    public function __construct(
        private readonly int $id,
        private readonly string $firstName,
        private readonly string $lastName,
        private readonly string $email,
        private readonly string $status,
        private readonly array $memberships,
        private readonly array $assignments,
        private readonly array $attendance,
        private readonly ?string $birthDate = null,
        private readonly ?string $personalIdentityNumber = null,
        private readonly array $guardianNotes = [],
        private readonly array $contactChanges = [],
        private readonly ?ContactDetails $contactDetails = null,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return list<ExportedMembership>
     */
    public function memberships(): array
    {
        return $this->memberships;
    }

    /**
     * @return list<ExportedAssignment>
     */
    public function assignments(): array
    {
        return $this->assignments;
    }

    /**
     * @return list<ExportedAttendance>
     */
    public function attendance(): array
    {
        return $this->attendance;
    }

    public function birthDate(): ?string
    {
        return $this->birthDate;
    }

    public function personalIdentityNumber(): ?string
    {
        return $this->personalIdentityNumber;
    }

    /**
     * @return list<string>
     */
    public function guardianNotes(): array
    {
        return $this->guardianNotes;
    }

    /**
     * When a part of the contact details changed, without the values. See ADR-0032.
     *
     * @return list<array{on: string, part: string}>
     */
    public function contactChanges(): array
    {
        return $this->contactChanges;
    }

    public function contactDetails(): ContactDetails
    {
        return $this->contactDetails ?? new ContactDetails();
    }
}
