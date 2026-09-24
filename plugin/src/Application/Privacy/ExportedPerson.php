<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Privacy;

final class ExportedPerson
{
    /**
     * @param list<ExportedMembership> $memberships
     * @param list<ExportedAssignment> $assignments
     * @param list<ExportedAttendance> $attendance
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
}
