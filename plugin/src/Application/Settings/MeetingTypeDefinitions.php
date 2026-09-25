<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Settings;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\MeetingRepository;
use Foreningssystem\Domain\Meeting\MeetingTemplateRepository;
use Foreningssystem\Domain\Meeting\MeetingType;
use Foreningssystem\Domain\Meeting\MeetingTypeRepository;

final class MeetingTypeDefinitions
{
    public function __construct(
        private readonly MeetingTypeRepository $types,
        private readonly MeetingRepository $meetings,
        private readonly MeetingTemplateRepository $templates,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
    ) {
    }

    public function create(string $name): MeetingType
    {
        $this->guard();
        $name = $this->cleanName($name);

        return $this->transaction->run(function () use ($name): MeetingType {
            $this->assertUniqueName($name, null);
            $existing = $this->types->all();
            $slug = CustomSlug::fromName($name, $this->slugs($existing), 'type');
            $sortOrder = 10;

            foreach ($existing as $type) {
                $sortOrder = max($sortOrder, $type->sortOrder() + 10);
            }

            return $this->types->add(new MeetingType(null, $slug, $name, $sortOrder));
        });
    }

    public function rename(int $id, string $name): void
    {
        $this->guard();
        $name = $this->cleanName($name);
        $this->transaction->run(function () use ($id, $name): void {
            $type = $this->require($id);
            $this->assertMutable($type);
            $this->assertUniqueName($name, $type->id());
            $this->types->save($type->withName($name));
        });
    }

    public function move(int $id, string $direction): void
    {
        $this->guard();

        if ($id < 1 || ($direction !== 'up' && $direction !== 'down')) {
            throw new StructureRuleException(StructureRuleException::INVALID);
        }

        $this->transaction->run(function () use ($id, $direction): void {
            $ordered = $this->sorted($this->types->all());
            $index = $this->indexOf($ordered, $id);
            $swap = $direction === 'up' ? $index - 1 : $index + 1;

            if ($swap < 0 || $swap >= count($ordered)) {
                return;
            }

            $moving = $ordered[$index];
            $ordered[$index] = $ordered[$swap];
            $ordered[$swap] = $moving;
            $sortOrder = 10;

            foreach ($ordered as $type) {
                $this->types->save($type->withSortOrder($sortOrder));
                $sortOrder += 10;
            }
        });
    }

    /**
     * @return list<MeetingType>
     */
    public function catalog(): array
    {
        $this->guard();

        return $this->sorted($this->types->all());
    }

    public function builtIn(MeetingType $type): bool
    {
        return BuiltinStructure::isMeeting($type->slug());
    }

    public function used(int $typeId): bool
    {
        foreach ($this->meetings->all() as $meeting) {
            if ($meeting->typeId() === $typeId) {
                return true;
            }
        }

        foreach ($this->templates->all() as $template) {
            if ($template->typeId() === $typeId) {
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

    private function assertMutable(MeetingType $type): void
    {
        if ($this->builtIn($type)) {
            throw new StructureRuleException(StructureRuleException::BUILTIN);
        }

        $id = $type->id();

        if ($id !== null && $this->used($id)) {
            throw new StructureRuleException(StructureRuleException::USED);
        }
    }

    private function assertUniqueName(string $name, ?int $exceptId): void
    {
        $normalized = VisibleName::normalize($name);
        $present = [];

        foreach ($this->types->all() as $type) {
            $present[] = $type->slug();

            if ($type->id() === $exceptId) {
                continue;
            }

            if (VisibleName::normalize($type->name()) === $normalized) {
                throw new StructureRuleException(StructureRuleException::DUPLICATE);
            }
        }

        if (in_array($normalized, BuiltinStructure::reservedMeetingNames($present), true)) {
            throw new StructureRuleException(StructureRuleException::DUPLICATE);
        }
    }

    private function require(int $id): MeetingType
    {
        if ($id < 1) {
            throw new StructureRuleException(StructureRuleException::INVALID);
        }

        $type = $this->types->find($id);

        if (! $type instanceof MeetingType) {
            throw new StructureRuleException(StructureRuleException::MISSING);
        }

        return $type;
    }

    /**
     * @param list<MeetingType> $types
     * @return list<string>
     */
    private function slugs(array $types): array
    {
        return array_map(static fn (MeetingType $type): string => $type->slug(), $types);
    }

    /**
     * @param list<MeetingType> $types
     */
    private function indexOf(array $types, int $id): int
    {
        foreach ($types as $index => $type) {
            if ($type->id() === $id) {
                return $index;
            }
        }

        throw new StructureRuleException(StructureRuleException::MISSING);
    }

    /**
     * @param list<MeetingType> $types
     * @return list<MeetingType>
     */
    private static function characters(string $value): int
    {
        $count = preg_match_all('/./u', $value);

        return $count === false ? 0 : $count;
    }

    private function sorted(array $types): array
    {
        usort(
            $types,
            static function (MeetingType $left, MeetingType $right): int {
                $byOrder = $left->sortOrder() <=> $right->sortOrder();

                return $byOrder !== 0 ? $byOrder : (($left->id() ?? 0) <=> ($right->id() ?? 0));
            }
        );

        return $types;
    }
}
