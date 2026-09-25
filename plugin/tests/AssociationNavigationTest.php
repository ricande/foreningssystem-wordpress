<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\Settings\BoardRoleDefinitions;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Domain\Access\RoleCapabilitySetting;
use Foreningssystem\Domain\Access\RoleSynchronizer;
use PHPUnit\Framework\TestCase;

final class AssociationNavigationTest extends TestCase
{
    public function test_access_association_is_a_known_capability(): void
    {
        self::assertSame('access_association', Capabilities::ACCESS_ASSOCIATION);
        self::assertContains(Capabilities::ACCESS_ASSOCIATION, Capabilities::all());
    }

    public function test_association_bundles_include_navigation_without_new_business_access(): void
    {
        $defaults = RoleBundles::defaults();

        self::assertSame([
            Capabilities::ACCESS_ASSOCIATION,
            Capabilities::VIEW_INTERNAL_MEETINGS,
            Capabilities::MANAGE_MEETINGS,
            Capabilities::RECORD_MEETING,
            Capabilities::MANAGE_DOCUMENTS,
            Capabilities::VIEW_BOARD_DOCUMENTS,
            Capabilities::VIEW_MEMBERS,
        ], $defaults[RoleBundles::SECRETARY]);
        self::assertSame(array_merge($defaults[RoleBundles::SECRETARY], [
            Capabilities::MANAGE_BOARD,
            Capabilities::FINALIZE_MINUTES,
            Capabilities::PUBLISH_MINUTES,
        ]), $defaults[RoleBundles::CHAIR]);
        self::assertSame([
            Capabilities::ACCESS_ASSOCIATION,
            Capabilities::VIEW_MEMBERS,
            Capabilities::EXPORT_MEMBERS,
        ], $defaults[RoleBundles::TREASURER]);
        self::assertSame([
            Capabilities::ACCESS_ASSOCIATION,
            Capabilities::VIEW_INTERNAL_MEETINGS,
            Capabilities::VIEW_BOARD_DOCUMENTS,
            Capabilities::VIEW_MEMBERS,
        ], $defaults[RoleBundles::BOARD_MEMBER]);

        foreach ($defaults as $capabilities) {
            self::assertNotContains(Capabilities::MANAGE_ASSOCIATION, $capabilities);
        }
    }

    public function test_a_settings_only_capability_set_opens_the_menu_and_settings_only(): void
    {
        $allowed = [Capabilities::ACCESS_ASSOCIATION, Capabilities::MANAGE_ASSOCIATION];

        self::assertContains(Capabilities::ACCESS_ASSOCIATION, $allowed);
        self::assertContains(Capabilities::MANAGE_ASSOCIATION, $allowed);
        self::assertNotContains(Capabilities::VIEW_MEMBERS, $allowed);
        self::assertNotContains(Capabilities::MANAGE_BOARD, $allowed);
        self::assertNotContains(Capabilities::VIEW_INTERNAL_MEETINGS, $allowed);
        self::assertNotContains(Capabilities::VIEW_BOARD_DOCUMENTS, $allowed);
        $this->definitions($allowed)->create('Material manager', false);
    }

    public function test_navigation_alone_cannot_change_association_settings(): void
    {
        try {
            $this->definitions([Capabilities::ACCESS_ASSOCIATION])->create('Material manager', false);
            self::fail('The menu capability changed a board role.');
        } catch (NotAllowed $error) {
            self::assertSame(Capabilities::MANAGE_ASSOCIATION, $error->getMessage());
        }
    }

    public function test_secretary_without_manage_association_cannot_change_settings(): void
    {
        $secretary = RoleBundles::defaults()[RoleBundles::SECRETARY];

        self::assertNotContains(Capabilities::MANAGE_ASSOCIATION, $secretary);

        try {
            $this->definitions($secretary)->create('Material manager', false);
            self::fail('A secretary changed association settings.');
        } catch (NotAllowed $error) {
            self::assertSame(Capabilities::MANAGE_ASSOCIATION, $error->getMessage());
        }
    }

    public function test_an_old_role_bundle_gains_menu_access_without_losing_capabilities(): void
    {
        $old = [
            Capabilities::VIEW_INTERNAL_MEETINGS,
            Capabilities::MANAGE_MEETINGS,
            Capabilities::RECORD_MEETING,
            Capabilities::MANAGE_DOCUMENTS,
            Capabilities::VIEW_BOARD_DOCUMENTS,
            Capabilities::VIEW_MEMBERS,
        ];
        $setting = RoleCapabilitySetting::fromArray([
            RoleBundles::SECRETARY => $old,
        ]);
        $store = new RecordingRoleStore();
        $synchronizer = new RoleSynchronizer($store);
        $synchronizer->sync($setting, []);
        $first = $store->capabilities[RoleBundles::SECRETARY];
        $synchronizer->sync($setting, []);

        self::assertSame(array_merge($old, [Capabilities::ACCESS_ASSOCIATION]), $first);
        self::assertSame($first, $store->capabilities[RoleBundles::SECRETARY]);
        self::assertContains(Capabilities::ACCESS_ASSOCIATION, $store->capabilities['administrator']);
        self::assertNotContains(Capabilities::MANAGE_ASSOCIATION, $first);
        self::assertContains(
            Capabilities::FINALIZE_MINUTES,
            $store->capabilities[RoleBundles::CHAIR]
        );
    }

    public function test_reloading_a_bundle_puts_menu_access_back(): void
    {
        $stored = RoleCapabilitySetting::defaults()
            ->revoke(RoleBundles::TREASURER, Capabilities::ACCESS_ASSOCIATION)
            ->toArray();

        self::assertNotContains(
            Capabilities::ACCESS_ASSOCIATION,
            $stored[RoleBundles::TREASURER]
        );

        $loaded = RoleCapabilitySetting::fromArray($stored);

        self::assertContains(Capabilities::VIEW_MEMBERS, $loaded->capabilitiesFor(RoleBundles::TREASURER));
        self::assertContains(Capabilities::EXPORT_MEMBERS, $loaded->capabilitiesFor(RoleBundles::TREASURER));
        self::assertContains(Capabilities::ACCESS_ASSOCIATION, $loaded->capabilitiesFor(RoleBundles::TREASURER));
        self::assertNotContains(Capabilities::MANAGE_ASSOCIATION, $loaded->capabilitiesFor(RoleBundles::TREASURER));
        self::assertNotContains(Capabilities::VIEW_INTERNAL_MEETINGS, $loaded->capabilitiesFor(RoleBundles::TREASURER));
    }

    /**
     * @param list<string> $allowed
     */
    private function definitions(array $allowed): BoardRoleDefinitions
    {
        return new BoardRoleDefinitions(
            new MemoryBoardRoleRepository(),
            new MemoryBoardAssignmentRepository(),
            new class($allowed) implements \Foreningssystem\Application\People\Authorizer {
                /** @param list<string> $allowed */
                public function __construct(private array $allowed)
                {
                }

                public function allows(string $capability): bool
                {
                    return in_array($capability, $this->allowed, true);
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
