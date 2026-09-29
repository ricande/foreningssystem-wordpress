<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

final class MemberRegisterPlan
{
    /**
     * @param list<string> $blockers
     * @param list<array{code: string, header: string}> $notices
     * @param list<MemberRegisterIssue> $issues
     * @param list<array{line: int, first_name: string, last_name: string, email: string, birth_date: string, person_status: string, membership_number: string, membership_type: string, membership_status: string, started_on: string, ended_on: string}> $readyRows
     */
    public function __construct(
        public readonly int $read,
        public readonly array $blockers,
        public readonly array $notices,
        public readonly array $issues,
        public readonly array $readyRows,
    ) {
    }

    public function ready(): int
    {
        return count($this->readyRows);
    }

    public function duplicates(): int
    {
        return count(array_filter(
            $this->issues,
            static fn (MemberRegisterIssue $issue): bool => $issue->kind === 'duplicate'
        ));
    }

    public function errors(): int
    {
        return count($this->blockers) + count(array_filter(
            $this->issues,
            static fn (MemberRegisterIssue $issue): bool => $issue->kind === 'error'
        ));
    }
}
