<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

interface LegacyMembershipGateway
{
    /**
     * @return list<array<string, mixed>>
     */
    public function legacyRows(): array;

    public function findMembershipId(string $number): ?int;

    public function insertMembership(string $number, string $kind): int;

    public function hasParticipant(int $membershipId, int $personId): bool;

    public function insertParticipant(int $membershipId, int $personId, string $startedOn): void;

    public function hasPeriod(int $membershipId, string $status, string $startedOn, ?string $endedOn): bool;

    public function insertPeriod(int $membershipId, string $status, string $startedOn, ?string $endedOn, string $historicalClass): void;

    /**
     * @param callable(): void $callback
     */
    public function transaction(callable $callback): void;
}
