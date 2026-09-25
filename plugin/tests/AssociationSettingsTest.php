<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Board\BoardDirectory;
use Foreningssystem\Application\Board\BoardService;
use Foreningssystem\Application\Meeting\MeetingService;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Application\Settings\BoardRoleDefinitions;
use Foreningssystem\Application\Settings\CustomSlug;
use Foreningssystem\Application\Settings\MeetingTypeDefinitions;
use Foreningssystem\Application\Settings\StructureRuleException;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardAssignment;
use Foreningssystem\Domain\Board\BoardAssignmentLedger;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingLifecycle;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MeetingTemplate;
use Foreningssystem\Domain\Meeting\MeetingType;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AssociationSettingsTest extends TestCase
{
    public function test_manage_association_can_create_a_custom_board_role(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $service = $this->roles($roles, new MemoryBoardAssignmentRepository(), true);
        $role = $service->create('Material manager', false);

        self::assertSame('Material manager', $role->name());
        self::assertFalse($role->allowsMultiple());
        self::assertNotNull($role->id());
    }

    public function test_a_user_without_manage_association_cannot_create_a_board_role(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $service = $this->roles($roles, new MemoryBoardAssignmentRepository(), false);

        try {
            $service->create('Material manager', false);
            self::fail('A user without manage_association created a role.');
        } catch (NotAllowed $error) {
            self::assertSame(Capabilities::MANAGE_ASSOCIATION, $error->getMessage());
        }

        self::assertSame([], $roles->all());
    }

    public function test_the_board_role_slug_is_generated_on_the_server(): void
    {
        $role = $this->roles(new MemoryBoardRoleRepository(), new MemoryBoardAssignmentRepository(), true)
            ->create('chairman', false);

        self::assertSame('custom_chairman', $role->slug());
        self::assertNotSame('chair', $role->slug());
    }

    public function test_a_generated_board_role_slug_matches_the_domain_format(): void
    {
        $role = $this->roles(new MemoryBoardRoleRepository(), new MemoryBoardAssignmentRepository(), true)
            ->create('Equipment manager', false);

        self::assertSame(1, preg_match('/^custom_[a-z0-9_]+$/', $role->slug()));
    }

    public function test_a_swedish_board_role_name_gets_a_safe_slug(): void
    {
        $slug = CustomSlug::fromName('Vice ordförande', [], 'role');
        $role = $this->roles(new MemoryBoardRoleRepository(), new MemoryBoardAssignmentRepository(), true)
            ->create('Vice ordförande', false);

        self::assertSame('custom_vice_ordforande', $slug);
        self::assertSame('custom_vice_ordforande', $role->slug());
    }

    public function test_a_board_role_slug_collision_gets_a_deterministic_suffix(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $service = $this->roles($roles, new MemoryBoardAssignmentRepository(), true);
        $first = $service->create('Vice ordförande', false);
        $second = $service->create('Vice-ordförande', true);

        self::assertSame('custom_vice_ordforande', $first->slug());
        self::assertSame('custom_vice_ordforande_2', $second->slug());
    }

    public function test_a_case_insensitive_duplicate_board_role_name_is_rejected(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10));
        $service = $this->roles($roles, new MemoryBoardAssignmentRepository(), true);
        $service->create('Materialansvarig', false);

        try {
            $service->create('materialansvarig', true);
            self::fail('A duplicate visible role name was saved.');
        } catch (StructureRuleException $error) {
            self::assertSame(StructureRuleException::DUPLICATE, $error->rule());
        }

        try {
            $service->create('Chair', false);
            self::fail('The English built-in label was saved as a second role.');
        } catch (StructureRuleException $error) {
            self::assertSame(StructureRuleException::DUPLICATE, $error->rule());
        }

        self::assertCount(2, $roles->all());
    }

    public function test_a_custom_board_role_appears_in_repository_order(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10));
        $roles->add(new BoardRole(null, 'treasurer', 'Kassör', false, 20));
        $service = $this->roles($roles, new MemoryBoardAssignmentRepository(), true);
        $service->create('Material manager', false);
        $again = $service->catalog();

        self::assertSame(
            ['chair', 'treasurer', 'custom_material_manager'],
            array_map(static fn (BoardRole $role): string => $role->slug(), $service->catalog())
        );
        self::assertSame(
            array_map(static fn (BoardRole $role): string => $role->slug(), $again),
            array_map(static fn (BoardRole $role): string => $role->slug(), $service->catalog())
        );
    }

    public function test_an_unused_custom_board_role_can_be_renamed(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $service = $this->roles($roles, new MemoryBoardAssignmentRepository(), true);
        $role = $service->create('Material manager', false);
        $service->rename((int) $role->id(), 'Equipment manager');

        self::assertSame('Equipment manager', $roles->find((int) $role->id())?->name());
    }

    public function test_an_unused_custom_board_role_can_change_its_holder_rule(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $service = $this->roles($roles, new MemoryBoardAssignmentRepository(), true);
        $role = $service->create('Material manager', false);
        $service->changeHolders((int) $role->id(), true);

        self::assertTrue($roles->find((int) $role->id())?->allowsMultiple());
    }

    public function test_renaming_a_custom_board_role_keeps_its_slug(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $service = $this->roles($roles, new MemoryBoardAssignmentRepository(), true);
        $role = $service->create('Material manager', false);
        $service->rename((int) $role->id(), 'Equipment manager');
        $saved = $roles->find((int) $role->id());

        self::assertSame('custom_material_manager', $saved?->slug());
        self::assertSame('Equipment manager', $saved?->name());
    }

    public function test_a_used_custom_board_role_cannot_be_renamed(): void
    {
        [$roles, $assignments, $service, $role] = $this->usedRole();

        try {
            $service->rename((int) $role->id(), 'Supreme manager');
            self::fail('A used role was renamed.');
        } catch (StructureRuleException $error) {
            self::assertSame(StructureRuleException::USED, $error->rule());
        }

        $saved = $roles->find((int) $role->id());
        self::assertSame('Material manager', $saved?->name());
        self::assertFalse($saved?->allowsMultiple());
        self::assertSame('custom_material_manager', $saved?->slug());
        self::assertCount(1, $assignments->all());
    }

    public function test_a_used_custom_board_role_cannot_change_its_holder_rule(): void
    {
        [$roles, , $service, $role] = $this->usedRole();

        try {
            $service->changeHolders((int) $role->id(), true);
            self::fail('A used role changed its holder rule.');
        } catch (StructureRuleException $error) {
            self::assertSame(StructureRuleException::USED, $error->rule());
        }

        self::assertFalse($roles->find((int) $role->id())?->allowsMultiple());
    }

    public function test_a_used_custom_board_role_can_be_reordered(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10));
        $assignments = new MemoryBoardAssignmentRepository();
        $service = $this->roles($roles, $assignments, true);
        $role = $service->create('Material manager', false);
        $assignments->add(new BoardAssignment(
            null,
            1,
            (int) $role->id(),
            AssociationDate::fromIso('2024-01-01'),
            null,
            '',
            ''
        ));
        $service->move((int) $role->id(), 'up');
        $order = array_map(static fn (BoardRole $item): string => $item->slug(), $service->catalog());

        self::assertSame(['custom_material_manager', 'chair'], $order);
        self::assertSame([10, 20], array_map(static fn (BoardRole $item): int => $item->sortOrder(), $service->catalog()));
    }

    public function test_a_built_in_board_role_cannot_be_renamed(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $chair = $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10));
        $service = $this->roles($roles, new MemoryBoardAssignmentRepository(), true);

        try {
            $service->rename((int) $chair->id(), 'Supreme Leader');
            self::fail('The chair role was renamed.');
        } catch (StructureRuleException $error) {
            self::assertSame(StructureRuleException::BUILTIN, $error->rule());
        }

        $saved = $roles->find((int) $chair->id());
        self::assertSame('chair', $saved?->slug());
        self::assertSame('Ordförande', $saved?->name());
        self::assertFalse($saved?->allowsMultiple());
    }

    public function test_a_built_in_board_role_cannot_change_its_holder_rule(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $chair = $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10));
        $service = $this->roles($roles, new MemoryBoardAssignmentRepository(), true);

        try {
            $service->changeHolders((int) $chair->id(), true);
            self::fail('The chair holder rule changed.');
        } catch (StructureRuleException $error) {
            self::assertSame(StructureRuleException::BUILTIN, $error->rule());
        }

        self::assertFalse($roles->find((int) $chair->id())?->allowsMultiple());
    }

    public function test_a_built_in_board_role_can_be_reordered(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $chair = $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10));
        $roles->add(new BoardRole(null, 'treasurer', 'Kassör', false, 20));
        $service = $this->roles($roles, new MemoryBoardAssignmentRepository(), true);
        $service->move((int) $chair->id(), 'down');

        self::assertSame(
            ['treasurer', 'chair'],
            array_map(static fn (BoardRole $role): string => $role->slug(), $service->catalog())
        );
        self::assertSame('Ordförande', $roles->find((int) $chair->id())?->name());
        self::assertFalse($roles->find((int) $chair->id())?->allowsMultiple());
    }

    public function test_moving_the_first_board_role_up_is_safe(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10));
        $roles->add(new BoardRole(null, 'treasurer', 'Kassör', false, 20));
        $service = $this->roles($roles, new MemoryBoardAssignmentRepository(), true);
        $chair = $service->catalog()[0];
        $service->move((int) $chair->id(), 'up');

        self::assertSame(
            ['chair', 'treasurer'],
            array_map(static fn (BoardRole $role): string => $role->slug(), $service->catalog())
        );
        self::assertSame(10, $roles->find((int) $chair->id())?->sortOrder());
    }

    public function test_moving_the_last_board_role_down_is_safe(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $roles->add(new BoardRole(null, 'chair', 'Ordförande', false, 10));
        $treasurer = $roles->add(new BoardRole(null, 'treasurer', 'Kassör', false, 20));
        $service = $this->roles($roles, new MemoryBoardAssignmentRepository(), true);
        $service->move((int) $treasurer->id(), 'down');

        self::assertSame(
            ['chair', 'treasurer'],
            array_map(static fn (BoardRole $role): string => $role->slug(), $service->catalog())
        );
        self::assertSame(20, $roles->find((int) $treasurer->id())?->sortOrder());
    }

    public function test_a_failed_board_role_write_is_not_reported_as_success(): void
    {
        $roles = new MemoryBoardRoleRepository();
        $roles->failNextWrite = true;
        $service = $this->roles($roles, new MemoryBoardAssignmentRepository(), true);

        try {
            $service->create('Material manager', false);
            self::fail('A failed write was reported as success.');
        } catch (StructureRuleException) {
            self::fail('A failed write was reported as a validation result.');
        } catch (RuntimeException $error) {
            self::assertSame('The board role could not be saved.', $error->getMessage());
        }

        self::assertSame([], $roles->all());
    }

    public function test_a_custom_board_role_is_available_to_the_existing_board_service(): void
    {
        $people = new MemoryPersonRepository();
        $memberships = new MemoryMembershipRepository();
        $roles = new MemoryBoardRoleRepository();
        $assignments = new MemoryBoardAssignmentRepository();
        $definitions = $this->roles($roles, $assignments, true, [Capabilities::MANAGE_ASSOCIATION, Capabilities::MANAGE_BOARD]);
        $role = $definitions->create('Material manager', false);
        $personId = (int) $people->add(new Person(null, 'Ada', 'Lovelace', 'ada@example.test', PersonStatus::Known, null))->id();
        $memberships->grant($personId, 'M-1', 'ordinarie', MembershipStatus::Active, AssociationDate::fromIso('2024-01-01'), null);
        $board = new BoardService(
            $people,
            $memberships,
            $roles,
            $assignments,
            new BoardAssignmentLedger(),
            $this->authorizer([Capabilities::MANAGE_ASSOCIATION, Capabilities::MANAGE_BOARD]),
            $this->transaction()
        );
        $board->place($personId, (int) $role->id(), AssociationDate::fromIso('2024-01-01'), null, 'material@example.test', '', AssociationDate::fromIso('2026-09-25'));
        $directory = new BoardDirectory($people, $memberships, $roles, $assignments);
        $selector = array_map(static fn (BoardRole $item): string => $item->slug(), $directory->roles());
        $seat = $directory->seats(AssociationDate::fromIso('2026-09-25'))[0];
        $public = $board->currentPublic(AssociationDate::fromIso('2026-09-25'));

        self::assertContains('custom_material_manager', $selector);
        self::assertSame('Material manager', $seat->roleName());
        self::assertSame('custom_material_manager', $seat->roleSlug());
        self::assertSame('Material manager', $public[0]->roleName());
        self::assertSame('custom_material_manager', $public[0]->roleSlug());
        self::assertSame('material@example.test', $public[0]->publicContact());

        try {
            $definitions->rename((int) $role->id(), 'Property manager');
            self::fail('The assigned role was renamed.');
        } catch (StructureRuleException $error) {
            self::assertSame(StructureRuleException::USED, $error->rule());
        }

        self::assertSame('Material manager', $roles->find((int) $role->id())?->name());
        self::assertFalse($roles->find((int) $role->id())?->allowsMultiple());
    }

    public function test_manage_association_can_create_a_custom_meeting_type(): void
    {
        $type = $this->types(new MemoryMeetingTypeRepository(), new MemoryMeetingRepository(), new MemoryMeetingTemplateRepository(), true)
            ->create('Budget meeting');

        self::assertSame('Budget meeting', $type->name());
        self::assertNotNull($type->id());
    }

    public function test_a_user_without_manage_association_cannot_create_a_meeting_type(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $service = $this->types($types, new MemoryMeetingRepository(), new MemoryMeetingTemplateRepository(), false);

        try {
            $service->create('Budget meeting');
            self::fail('A user without manage_association created a meeting type.');
        } catch (NotAllowed $error) {
            self::assertSame(Capabilities::MANAGE_ASSOCIATION, $error->getMessage());
        }

        self::assertSame([], $types->all());
    }

    public function test_the_meeting_type_slug_is_generated_on_the_server(): void
    {
        $type = $this->types(new MemoryMeetingTypeRepository(), new MemoryMeetingRepository(), new MemoryMeetingTemplateRepository(), true)
            ->create('Budget meeting');

        self::assertSame('custom_budget_meeting', $type->slug());
        self::assertNotSame('annual_meeting', $type->slug());
    }

    public function test_a_swedish_meeting_type_name_gets_a_safe_slug(): void
    {
        $slug = CustomSlug::fromName('Budgetmöte', [], 'type');
        $type = $this->types(new MemoryMeetingTypeRepository(), new MemoryMeetingRepository(), new MemoryMeetingTemplateRepository(), true)
            ->create('Budgetmöte');

        self::assertSame('custom_budgetmote', $slug);
        self::assertSame('custom_budgetmote', $type->slug());
    }

    public function test_a_meeting_type_slug_collision_gets_a_deterministic_suffix(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $service = $this->types($types, new MemoryMeetingRepository(), new MemoryMeetingTemplateRepository(), true);
        $first = $service->create('Budgetmöte');
        $second = $service->create('Budgetmote');

        self::assertSame('custom_budgetmote', $first->slug());
        self::assertSame('custom_budgetmote_2', $second->slug());
    }

    public function test_a_case_insensitive_duplicate_meeting_type_name_is_rejected(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $types->add(new MeetingType(null, 'annual_meeting', 'Årsmöte', 20));
        $service = $this->types($types, new MemoryMeetingRepository(), new MemoryMeetingTemplateRepository(), true);
        $service->create('Budget meeting');

        try {
            $service->create('budget meeting');
            self::fail('A duplicate visible meeting type was saved.');
        } catch (StructureRuleException $error) {
            self::assertSame(StructureRuleException::DUPLICATE, $error->rule());
        }

        try {
            $service->create('Annual meeting');
            self::fail('The English built-in meeting label was saved as a second type.');
        } catch (StructureRuleException $error) {
            self::assertSame(StructureRuleException::DUPLICATE, $error->rule());
        }

        self::assertCount(2, $types->all());
    }

    public function test_an_unused_custom_meeting_type_can_be_renamed_without_changing_its_slug(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $service = $this->types($types, new MemoryMeetingRepository(), new MemoryMeetingTemplateRepository(), true);
        $type = $service->create('Budget meeting');
        $service->rename((int) $type->id(), 'Planning meeting');
        $saved = $types->find((int) $type->id());

        self::assertSame('Planning meeting', $saved?->name());
        self::assertSame('custom_budget_meeting', $saved?->slug());
    }

    public function test_a_meeting_type_used_by_a_meeting_cannot_be_renamed(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $meetings = new MemoryMeetingRepository();
        $service = $this->types($types, $meetings, new MemoryMeetingTemplateRepository(), true);
        $type = $service->create('Budget meeting');
        $meetings->add(new Meeting(
            null,
            (int) $type->id(),
            'Budget',
            MeetingMoment::fromLocal('2026-10-01 18:00:00'),
            'Hall',
            MeetingStatus::Planned
        ));

        try {
            $service->rename((int) $type->id(), 'Planning meeting');
            self::fail('A meeting type used by a meeting was renamed.');
        } catch (StructureRuleException $error) {
            self::assertSame(StructureRuleException::USED, $error->rule());
        }

        self::assertSame('Budget meeting', $types->find((int) $type->id())?->name());
        self::assertSame('custom_budget_meeting', $types->find((int) $type->id())?->slug());
    }

    public function test_a_meeting_type_used_only_by_a_template_cannot_be_renamed(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $templates = new MemoryMeetingTemplateRepository();
        $service = $this->types($types, new MemoryMeetingRepository(), $templates, true);
        $type = $service->create('Budget meeting');
        $templates->add(new MeetingTemplate(null, (int) $type->id(), 'Budget headings'));

        try {
            $service->rename((int) $type->id(), 'Planning meeting');
            self::fail('A meeting type used by a template was renamed.');
        } catch (StructureRuleException $error) {
            self::assertSame(StructureRuleException::USED, $error->rule());
        }

        self::assertSame('Budget meeting', $types->find((int) $type->id())?->name());
    }

    public function test_a_used_meeting_type_can_be_reordered(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $types->add(new MeetingType(null, 'board_meeting', 'Styrelsemöte', 10));
        $meetings = new MemoryMeetingRepository();
        $service = $this->types($types, $meetings, new MemoryMeetingTemplateRepository(), true);
        $type = $service->create('Budget meeting');
        $meetings->add(new Meeting(
            null,
            (int) $type->id(),
            'Budget',
            MeetingMoment::fromLocal('2026-10-01 18:00:00'),
            'Hall',
            MeetingStatus::Planned
        ));
        $service->move((int) $type->id(), 'up');

        self::assertSame(
            ['custom_budget_meeting', 'board_meeting'],
            array_map(static fn (MeetingType $item): string => $item->slug(), $service->catalog())
        );
        self::assertSame('Budget meeting', $types->find((int) $type->id())?->name());
    }

    public function test_a_built_in_meeting_type_cannot_be_renamed(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $annual = $types->add(new MeetingType(null, 'annual_meeting', 'Årsmöte', 20));
        $service = $this->types($types, new MemoryMeetingRepository(), new MemoryMeetingTemplateRepository(), true);

        try {
            $service->rename((int) $annual->id(), 'Supreme meeting');
            self::fail('The annual meeting type was renamed.');
        } catch (StructureRuleException $error) {
            self::assertSame(StructureRuleException::BUILTIN, $error->rule());
        }

        $saved = $types->find((int) $annual->id());
        self::assertSame('annual_meeting', $saved?->slug());
        self::assertSame('Årsmöte', $saved?->name());
    }

    public function test_a_built_in_meeting_type_can_be_reordered(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $annual = $types->add(new MeetingType(null, 'annual_meeting', 'Årsmöte', 20));
        $types->add(new MeetingType(null, 'board_meeting', 'Styrelsemöte', 10));
        $service = $this->types($types, new MemoryMeetingRepository(), new MemoryMeetingTemplateRepository(), true);
        $service->move((int) $annual->id(), 'up');

        self::assertSame(
            ['annual_meeting', 'board_meeting'],
            array_map(static fn (MeetingType $type): string => $type->slug(), $service->catalog())
        );
        self::assertSame('Årsmöte', $types->find((int) $annual->id())?->name());
    }

    public function test_moving_a_meeting_type_past_the_ends_is_safe(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $board = $types->add(new MeetingType(null, 'board_meeting', 'Styrelsemöte', 10));
        $annual = $types->add(new MeetingType(null, 'annual_meeting', 'Årsmöte', 20));
        $service = $this->types($types, new MemoryMeetingRepository(), new MemoryMeetingTemplateRepository(), true);
        $service->move((int) $board->id(), 'up');
        $service->move((int) $annual->id(), 'down');

        self::assertSame(
            ['board_meeting', 'annual_meeting'],
            array_map(static fn (MeetingType $type): string => $type->slug(), $service->catalog())
        );
        self::assertSame(10, $types->find((int) $board->id())?->sortOrder());
        self::assertSame(20, $types->find((int) $annual->id())?->sortOrder());
    }

    public function test_a_failed_meeting_type_write_is_not_reported_as_success(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $types->failNextWrite = true;
        $service = $this->types($types, new MemoryMeetingRepository(), new MemoryMeetingTemplateRepository(), true);

        try {
            $service->create('Budget meeting');
            self::fail('A failed write was reported as success.');
        } catch (StructureRuleException) {
            self::fail('A failed write was reported as a validation result.');
        } catch (RuntimeException $error) {
            self::assertSame('The meeting type could not be saved.', $error->getMessage());
        }

        self::assertSame([], $types->all());
    }

    public function test_a_custom_meeting_type_can_be_scheduled_and_then_its_name_is_locked(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $meetings = new MemoryMeetingRepository();
        $definitions = $this->types($types, $meetings, new MemoryMeetingTemplateRepository(), true, [
            Capabilities::MANAGE_ASSOCIATION,
            Capabilities::MANAGE_MEETINGS,
        ]);
        $type = $definitions->create('Budget meeting');
        $meetingService = new MeetingService(
            $types,
            $meetings,
            new MeetingLifecycle(),
            $this->authorizer([
                Capabilities::MANAGE_ASSOCIATION,
                Capabilities::MANAGE_MEETINGS,
                Capabilities::VIEW_INTERNAL_MEETINGS,
            ]),
            $this->transaction()
        );
        $meetingId = $meetingService->schedule(
            (int) $type->id(),
            'Budget',
            MeetingMoment::fromLocal('2026-10-01 18:00:00'),
            'Hall'
        );

        self::assertContains('custom_budget_meeting', array_map(
            static fn (MeetingType $item): string => $item->slug(),
            $meetingService->types()
        ));
        self::assertSame((int) $type->id(), $meetings->find($meetingId)?->typeId());

        try {
            $definitions->rename((int) $type->id(), 'Planning meeting');
            self::fail('A scheduled meeting type was renamed.');
        } catch (StructureRuleException $error) {
            self::assertSame(StructureRuleException::USED, $error->rule());
        }

        self::assertSame('Budget meeting', $types->find((int) $type->id())?->name());
    }

    /**
     * @return array{0: MemoryBoardRoleRepository, 1: MemoryBoardAssignmentRepository, 2: BoardRoleDefinitions, 3: BoardRole}
     */
    private function usedRole(): array
    {
        $roles = new MemoryBoardRoleRepository();
        $assignments = new MemoryBoardAssignmentRepository();
        $service = $this->roles($roles, $assignments, true);
        $role = $service->create('Material manager', false);
        $assignments->add(new BoardAssignment(
            null,
            1,
            (int) $role->id(),
            AssociationDate::fromIso('2024-01-01'),
            AssociationDate::fromIso('2024-12-31'),
            '',
            ''
        ));

        return [$roles, $assignments, $service, $role];
    }

    /**
     * @param list<string>|null $allowed
     */
    private function roles(
        MemoryBoardRoleRepository $roles,
        MemoryBoardAssignmentRepository $assignments,
        bool $allowed,
        ?array $allowedCapabilities = null,
    ): BoardRoleDefinitions {
        return new BoardRoleDefinitions(
            $roles,
            $assignments,
            $this->authorizer($allowed ? ($allowedCapabilities ?? [Capabilities::MANAGE_ASSOCIATION]) : []),
            $this->transaction()
        );
    }

    /**
     * @param list<string>|null $allowedCapabilities
     */
    private function types(
        MemoryMeetingTypeRepository $types,
        MemoryMeetingRepository $meetings,
        MemoryMeetingTemplateRepository $templates,
        bool $allowed,
        ?array $allowedCapabilities = null,
    ): MeetingTypeDefinitions {
        return new MeetingTypeDefinitions(
            $types,
            $meetings,
            $templates,
            $this->authorizer($allowed ? ($allowedCapabilities ?? [Capabilities::MANAGE_ASSOCIATION]) : []),
            $this->transaction()
        );
    }

    /**
     * @param list<string> $allowed
     */
    private function authorizer(array $allowed): Authorizer
    {
        return new class($allowed) implements Authorizer {
            /** @param list<string> $allowed */
            public function __construct(private array $allowed)
            {
            }

            public function allows(string $capability): bool
            {
                return in_array($capability, $this->allowed, true);
            }
        };
    }

    private function transaction(): Transaction
    {
        return new class implements Transaction {
            public function run(callable $callback): mixed
            {
                return $callback();
            }
        };
    }
}
