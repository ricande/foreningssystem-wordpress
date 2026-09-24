<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Board\BoardService;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardAssignmentLedger;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipPeriod;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use PHPUnit\Framework\TestCase;

final class PublicBoardTest extends TestCase
{
    public function test_the_public_board_shows_name_role_and_public_contact_only(): void
    {
        $people = new MemoryPersonRepository();
        $roles = new MemoryBoardRoleRepository();
        $assignments = new MemoryBoardAssignmentRepository();
        $ada = (int) $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null))->id();
        $grace = (int) $people->add(new Person(null, 'Grace', 'Hopper', 'grace@example.test', PersonStatus::Deceased, null))->id();
        $past = (int) $people->add(new Person(null, 'Past', 'Holder', 'past@example.test', PersonStatus::Known, null))->id();
        $chair = (int) $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10))->id();
        $auditor = (int) $roles->add(new BoardRole(null, 'auditor', 'Revisor', true, 50))->id();
        $assignments->add(new BoardAssignment(null, $ada, $chair, AssociationDate::fromIso('2024-01-01'), null, 'ordf@example.test', '2024'));
        $assignments->add(new BoardAssignment(null, $grace, $auditor, AssociationDate::fromIso('2024-01-01'), null, 'revisor@example.test', ''));
        $assignments->add(new BoardAssignment(null, $past, $chair, AssociationDate::fromIso('2023-01-01'), AssociationDate::fromIso('2023-12-31'), 'gammal@example.test', ''));
        $service = $this->service($people, new MemoryMembershipRepository(), $roles, $assignments, false);

        $seats = $service->currentPublic(AssociationDate::fromIso('2024-06-01'));
        $rendered = '';

        foreach ($seats as $seat) {
            $rendered .= $seat->personName() . ' ' . $seat->roleName() . ' ' . $seat->publicContact();
        }

        self::assertCount(1, $seats);
        self::assertSame('Ada Lovelace', $seats[0]->personName());
        self::assertSame('Ordförande', $seats[0]->roleName());
        self::assertSame('ordf@example.test', $seats[0]->publicContact());
        self::assertStringNotContainsString('ada@example.test', $rendered);
        self::assertStringNotContainsString('grace@example.test', $rendered);
        self::assertStringNotContainsString('Grace Hopper', $rendered);
        self::assertStringNotContainsString('gammal@example.test', $rendered);
        self::assertSame([], $service->currentPublic(AssociationDate::fromIso('2020-01-01')));
    }

    public function test_replacing_a_role_updates_the_public_board_without_a_reader_capability(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $roles = new MemoryBoardRoleRepository();
        $assignments = new MemoryBoardAssignmentRepository();
        $ada = (int) $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null))->id();
        $grace = (int) $people->add(new Person(null, 'Kim', 'Lab <X>', 'kim@example.test', PersonStatus::Known, null))->id();
        $memberships->grant($ada, 'M-1', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $memberships->grant($grace, 'M-2', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $chair = (int) $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10))->id();
        $service = $this->service($people, $memberships, $roles, $assignments, true);
        $service->place($ada, $chair, AssociationDate::fromIso('2024-01-01'), null, 'gammal@example.test', '');
        $service->place($grace, $chair, AssociationDate::fromIso('2024-06-01'), null, '', '');

        $reader = $this->service($people, $memberships, $roles, $assignments, false);
        $seats = $reader->currentPublic(AssociationDate::fromIso('2024-06-01'));

        self::assertCount(1, $seats);
        self::assertSame('Kim Lab <X>', $seats[0]->personName());
        self::assertSame('', $seats[0]->publicContact());
        self::assertStringNotContainsString('ada@example.test', $seats[0]->personName() . $seats[0]->publicContact());
        self::assertStringNotContainsString('kim@example.test', $seats[0]->personName() . $seats[0]->publicContact());
    }

    private function service(
        MemoryPersonRepository $people,
        MemoryMembershipRepository $memberships,
        MemoryBoardRoleRepository $roles,
        MemoryBoardAssignmentRepository $assignments,
        bool $mayManage,
    ): BoardService {
        return new BoardService(
            $people,
            $memberships,
            $roles,
            $assignments,
            new BoardAssignmentLedger(),
            new class ($mayManage) implements Authorizer {
                public function __construct(private bool $mayManage)
                {
                }

                public function allows(string $capability): bool
                {
                    return $this->mayManage && $capability === Capabilities::MANAGE_BOARD;
                }
            },
            new class implements \Foreningssystem\Application\People\Transaction {
                public function run(callable $callback): mixed
                {
                    return $callback();
                }
            }
        );
    }
}
