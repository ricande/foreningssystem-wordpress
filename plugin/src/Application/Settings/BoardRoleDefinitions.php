<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Settings;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardAssignmentRepository;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Board\BoardRoleRepository;

final class BoardRoleDefinitions
{
    public function __construct(
        private readonly BoardRoleRepository $roles,
        private readonly BoardAssignmentRepository $assignments,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
    ) {
    }

    public function create(string $name, bool $allowsMultiple): BoardRole
    {
        $this->guard();
        $name = $this->cleanName($name);

        return $this->transaction->run(function () use ($name, $allowsMultiple): BoardRole {
            $this->assertUniqueName($name, null);
            $existing = $this->roles->all();
            $slug = CustomSlug::fromName($name, $this->slugs($existing), 'role');
            $sortOrder = 10;

            foreach ($existing as $role) {
                $sortOrder = max($sortOrder, $role->sortOrder() + 10);
            }

            return $this->roles->add(new BoardRole(null, $slug, $name, $allowsMultiple, $sortOrder));
        });
    }

    public function update(int $id, string $name, bool $allowsMultiple): void
    {
        $this->guard();
        $name = $this->cleanName($name);
        $this->transaction->run(function () use ($id, $name, $allowsMultiple): void {
            $role = $this->require($id);
            $this->assertMutable($role);
            $this->assertUniqueName($name, $role->id());
            $this->roles->save($role->withName($name)->withAllowsMultiple($allowsMultiple));
        });
    }

    public function rename(int $id, string $name): void
    {
        $this->guard();
        $name = $this->cleanName($name);
        $this->transaction->run(function () use ($id, $name): void {
            $role = $this->require($id);
            $this->assertMutable($role);
            $this->assertUniqueName($name, $role->id());
            $this->roles->save($role->withName($name));
        });
    }

    public function changeHolders(int $id, bool $allowsMultiple): void
    {
        $this->guard();
        $this->transaction->run(function () use ($id, $allowsMultiple): void {
            $role = $this->require($id);
            $this->assertMutable($role);
            $this->roles->save($role->withAllowsMultiple($allowsMultiple));
        });
    }

    public function move(int $id, string $direction): void
    {
        $this->guard();

        if ($id < 1 || ($direction !== 'up' && $direction !== 'down')) {
            throw new StructureRuleException(StructureRuleException::INVALID);
        }

        $this->transaction->run(function () use ($id, $direction): void {
            $ordered = $this->sorted($this->roles->all());
            $index = $this->indexOf($ordered, $id);
            $swap = $direction === 'up' ? $index - 1 : $index + 1;

            if ($swap < 0 || $swap >= count($ordered)) {
                return;
            }

            $moving = $ordered[$index];
            $ordered[$index] = $ordered[$swap];
            $ordered[$swap] = $moving;
            $sortOrder = 10;

            foreach ($ordered as $role) {
                $this->roles->save($role->withSortOrder($sortOrder));
                $sortOrder += 10;
            }
        });
    }

    /**
     * @return list<BoardRole>
     */
    public function catalog(): array
    {
        $this->guard();

        return $this->sorted($this->roles->all());
    }

    public function builtIn(BoardRole $role): bool
    {
        return BuiltinStructure::isBoard($role->slug());
    }

    public function used(int $roleId): bool
    {
        foreach ($this->assignments->all() as $assignment) {
            if ($assignment->roleId() === $roleId) {
                return true;
            }
        }

        return false;
    }

    private function guard(): void
    {
        if (! $this->authorizer->allows(Capabilities::MANAGE_ASSOCIATION)) {
            throw new NotAllowed(Capabilities::MANAGE_ASSOCIATION);
        }
    }

    private function cleanName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || self::characters($name) > 100) {
            throw new StructureRuleException(StructureRuleException::INVALID);
        }

        return $name;
    }

    private function assertMutable(BoardRole $role): void
    {
        if ($this->builtIn($role)) {
            throw new StructureRuleException(StructureRuleException::BUILTIN);
        }

        $id = $role->id();

        if ($id !== null && $this->used($id)) {
            throw new StructureRuleException(StructureRuleException::USED);
        }
    }

    private function assertUniqueName(string $name, ?int $exceptId): void
    {
        $normalized = VisibleName::normalize($name);
        $present = [];

        foreach ($this->roles->all() as $role) {
            $present[] = $role->slug();

            if ($role->id() === $exceptId) {
                continue;
            }

            if (VisibleName::normalize($role->name()) === $normalized) {
                throw new StructureRuleException(StructureRuleException::DUPLICATE);
            }
        }

        if (in_array($normalized, BuiltinStructure::reservedBoardNames($present), true)) {
            throw new StructureRuleException(StructureRuleException::DUPLICATE);
        }
    }

    private function require(int $id): BoardRole
    {
        if ($id < 1) {
            throw new StructureRuleException(StructureRuleException::INVALID);
        }

        $role = $this->roles->find($id);

        if (! $role instanceof BoardRole) {
            throw new StructureRuleException(StructureRuleException::MISSING);
        }

        return $role;
    }

    /**
     * @param list<BoardRole> $roles
     * @return list<string>
     */
    private function slugs(array $roles): array
    {
        return array_map(static fn (BoardRole $role): string => $role->slug(), $roles);
    }

    /**
     * @param list<BoardRole> $roles
     */
    private function indexOf(array $roles, int $id): int
    {
        foreach ($roles as $index => $role) {
            if ($role->id() === $id) {
                return $index;
            }
        }

        throw new StructureRuleException(StructureRuleException::MISSING);
    }

    /**
     * @param list<BoardRole> $roles
     * @return list<BoardRole>
     */
    private static function characters(string $value): int
    {
        $count = preg_match_all('/./u', $value);

        return $count === false ? 0 : $count;
    }

    private function sorted(array $roles): array
    {
        usort(
            $roles,
            static function (BoardRole $left, BoardRole $right): int {
                $byOrder = $left->sortOrder() <=> $right->sortOrder();

                return $byOrder !== 0 ? $byOrder : (($left->id() ?? 0) <=> ($right->id() ?? 0));
            }
        );

        return $roles;
    }
}
