<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Board\EndOpenBoardAssignments;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\MemberDirectory;
use Foreningssystem\Application\People\PeopleService;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardAssignmentLedger;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\Membership;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipLedger;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Domain\Organization\Organization;
use Foreningssystem\Domain\Organization\OrganizationNumber;
use PHPUnit\Framework\TestCase;

final class MemberDirectoryTest extends TestCase
{
    public function test_the_directory_can_be_searched_and_filtered_without_identity_numbers(): void
    {
        [$service, $people, $memberships, , $directory] = $this->world();
        $today = AssociationDate::fromIso('2026-09-24');
        $anna = $service->register('Anna', 'Andersson', 'anna-dir@example.test', '1042', 'ordinary', AssociationDate::fromIso('2024-01-01'));
        $service->register('Bo', 'Berg', 'bo-dir@example.test', '2000', 'youth', AssociationDate::fromIso('2024-05-01'), AssociationDate::fromIso('2012-04-17'), $today);

        self::assertSame('1042', $directory->listRows('Anna', '', '', $today)[0]['number']);
        self::assertSame('Anna Andersson', $directory->listRows('1042', '', 'active', $today)[0]['title']);
        self::assertSame('youth', $directory->listRows('', 'youth', '', $today)[0]['kind']);
        self::assertSame([], $directory->listRows('missing-person', '', '', $today));
        self::assertStringNotContainsString('20120417', json_encode($directory->personDetail($anna, $today), JSON_THROW_ON_ERROR));

        $periodId = (int) $memberships->periodsForMembership((int) $memberships->findMembershipByNumber('2000')?->id())[0]->id();
        $service->endMembership($periodId, AssociationDate::fromIso('2025-12-31'));
        $history = $directory->listRows('Bo', '', 'history', $today);

        self::assertSame('history', $history[0]['state']);
        self::assertTrue($history[0]['has_history']);
        $detail = $directory->personDetail((int) $people->all()[1]->id(), $today);
        self::assertIsArray($detail);
        self::assertSame('2024-05-01', $detail['memberships'][0]['periods'][0]['started_on']);
        self::assertSame('2025-12-31', $detail['memberships'][0]['periods'][0]['ended_on']);
    }

    public function test_family_history_and_a_company_contact_stay_distinct(): void
    {
        [$service, $people, $memberships, $organizations, $directory] = $this->world();
        $today = AssociationDate::fromIso('2026-09-24');
        $karin = $service->register('Karin', 'Andersson', 'karin-dir@example.test', 'F-100', 'family', AssociationDate::fromIso('2024-01-01'));
        $familyId = (int) $memberships->findMembershipByNumber('F-100')?->id();
        $lisa = $service->addPersonToMembership($familyId, 'Lisa', 'Andersson', 'lisa-dir@example.test', AssociationDate::fromIso('2012-04-17'), AssociationDate::fromIso('2020-01-01'), ParticipantRole::Member, false, $today);
        $service->endParticipation($familyId, $lisa, AssociationDate::fromIso('2022-12-31'));
        $service->addParticipant($familyId, $lisa, ParticipantRole::Member, false, AssociationDate::fromIso('2025-03-01'));
        $anna = $service->register('Anna', 'Andersson', 'anna-dir@example.test', '1042', 'ordinary', AssociationDate::fromIso('2024-01-01'));

        try {
            $service->addParticipant($familyId, $anna, ParticipantRole::Member, false, AssociationDate::fromIso('2024-02-01'));
            self::fail('Overlapping member coverage should be rejected.');
        } catch (MembershipRuleException $error) {
            self::assertSame('Membership periods cannot overlap.', $error->getMessage());
        }

        $lisaDetail = $directory->personDetail($lisa, $today);
        self::assertIsArray($lisaDetail);
        $lisaIntervals = array_values(array_filter(
            $lisaDetail['memberships'][0]['participants'],
            static fn (array $participant): bool => $participant['person_id'] === $lisa
        ));
        self::assertSame('2020-01-01', $lisaIntervals[0]['started_on']);
        self::assertSame('2022-12-31', $lisaIntervals[0]['ended_on']);
        self::assertSame('2025-03-01', $lisaIntervals[1]['started_on']);
        self::assertNull($lisaIntervals[1]['ended_on']);

        $saved = $organizations->add(new Organization(null, 'Exempel AB', OrganizationNumber::parse('556012-3456'), '', ''));
        $company = $memberships->addMembership(new Membership(null, 'C-0042', MembershipKind::Company, $saved->id()));
        $memberships->add(new MembershipPeriod(null, (int) $company->id(), MembershipStatus::Active, AssociationDate::fromIso('2025-01-01'), null, 'company'));
        $before = count(array_filter(
            $directory->listRows('', '', 'active', $today),
            static fn (array $row): bool => $row['state'] === 'active' && $row['kind'] !== 'company'
        ));
        $service->addParticipant((int) $company->id(), $anna, ParticipantRole::Contact, true, AssociationDate::fromIso('2025-01-01'));
        $after = count(array_filter(
            $directory->listRows('', '', 'active', $today),
            static fn (array $row): bool => $row['state'] === 'active' && $row['kind'] !== 'company'
        ));
        $annaRow = $directory->listRows('anna-dir@example.test', '', '', $today)[0];
        $companyRow = $directory->listRows('C-0042', 'company', '', $today)[0];
        $companyDetail = $directory->membershipDetail('C-0042');

        self::assertSame($before, $after);
        self::assertSame('Exempel AB', $annaRow['context']);
        self::assertSame('active', $annaRow['state']);
        self::assertSame('Exempel AB', $companyRow['title']);
        self::assertIsArray($companyDetail);
        self::assertSame('contact', $companyDetail['participants'][0]['role']);
        self::assertNotNull($people->find($anna));
    }

    /**
     * @return array{0: PeopleService, 1: MemoryPersonRepository, 2: MemoryMembershipRepository, 3: MemoryOrganizationRepository, 4: MemberDirectory}
     */
    private function world(): array
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $organizations = new MemoryOrganizationRepository();
        $service = new PeopleService(
            $people,
            $memberships,
            new MembershipLedger(),
            new class implements Authorizer {
                public function allows(string $capability): bool
                {
                    return in_array($capability, [Capabilities::EDIT_MEMBERS, Capabilities::VIEW_MEMBERS], true);
                }
            },
            new class implements Transaction {
                public function run(callable $callback): mixed
                {
                    return $callback();
                }
            },
            new EndOpenBoardAssignments(new MemoryBoardAssignmentRepository(), new BoardAssignmentLedger())
        );

        return [$service, $people, $memberships, $organizations, new MemberDirectory($people, $memberships, $organizations)];
    }
}
