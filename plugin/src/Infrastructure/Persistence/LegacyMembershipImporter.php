<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\Persistence;

/**
 * Copies each schema 14 membership row into one membership, one member participant and one period.
 * A membership that already exists is completed rather than skipped, so a partial copy can be run again.
 * Distinct membership numbers stay distinct.
 */
final class LegacyMembershipImporter
{
    public function copy(LegacyMembershipGateway $gateway): void
    {
        foreach ($gateway->legacyRows() as $row) {
            $gateway->transaction(function () use ($gateway, $row): void {
                $this->copyRow($gateway, $row);
            });
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function copyRow(LegacyMembershipGateway $gateway, array $row): void
    {
        $number = (string) $row['membership_number'];
        $type = (string) $row['membership_type'];
        $kind = MembershipAggregateSchemaMigration::kindFromLegacy($type);
        $membershipId = $gateway->findMembershipId($number) ?? $gateway->insertMembership($number, $kind === 'company' ? 'ordinary' : $kind);
        $personId = (int) $row['person_id'];
        $startedOn = (string) $row['started_on'];

        if (! $gateway->hasParticipant($membershipId, $personId)) {
            $gateway->insertParticipant($membershipId, $personId, $startedOn);
        }

        $endedOn = $row['ended_on'] ?? null;
        $ended = is_string($endedOn) && $endedOn !== '' && $endedOn !== '0000-00-00' ? $endedOn : null;

        if (! $gateway->hasPeriod($membershipId, (string) $row['status'], $startedOn, $ended)) {
            $gateway->insertPeriod($membershipId, (string) $row['status'], $startedOn, $ended, $type);
        }
    }
}
