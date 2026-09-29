<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\AgendaItem;
use Foreningssystem\Domain\Meeting\AgendaOrder;
use Foreningssystem\Domain\Meeting\AgendaRepository;
use Foreningssystem\Domain\Meeting\MeetingRepository;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MeetingTemplate;
use Foreningssystem\Domain\Meeting\MeetingTemplateItem;
use Foreningssystem\Domain\Meeting\MeetingTemplateItemRepository;
use Foreningssystem\Domain\Meeting\MeetingTemplateRepository;
use Foreningssystem\Domain\Meeting\MeetingTypeRepository;

final class MeetingTemplates
{
    public function __construct(
        private readonly MeetingTypeRepository $types,
        private readonly MeetingRepository $meetings,
        private readonly AgendaRepository $agenda,
        private readonly MeetingTemplateRepository $templates,
        private readonly MeetingTemplateItemRepository $items,
        private readonly AgendaOrder $order,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
    ) {
    }

    public function create(int $typeId, string $name): int
    {
        $this->requireManage();

        if ($this->types->find($typeId) === null) {
            throw new MeetingRuleException('Meeting type was not found.');
        }

        $saved = $this->transaction->run(
            fn (): MeetingTemplate => $this->templates->add(new MeetingTemplate(null, $typeId, $name))
        );
        $id = $saved->id();

        if ($id === null) {
            throw new \RuntimeException('The meeting template was not saved.');
        }

        return $id;
    }

    /**
     * Copies a system starting point into a new association-owned template.
     * The stored headings are the caller's wording, so a translation is saved as ordinary text.
     *
     * @param list<string> $headings
     */
    public function createFromStarter(string $key, string $name, int $emptyTypeId, array $headings): int
    {
        $this->requireManage();
        $starter = MeetingStarterCatalog::find($key);

        if ($starter === null || count($headings) > count($starter->headings())) {
            throw new MeetingRuleException('Meeting template was not found.');
        }

        $typeId = $emptyTypeId;

        if ($starter->typeSlug() !== '') {
            $type = $this->types->findBySlug($starter->typeSlug());

            if ($type === null || $type->id() === null) {
                throw new MeetingRuleException('Meeting type was not found.');
            }

            $typeId = $type->id();
        }

        return $this->createWithHeadings($typeId, $name, $headings);
    }

    /**
     * @param list<string> $headings
     */
    public function createWithHeadings(int $typeId, string $name, array $headings): int
    {
        $this->requireManage();

        if ($this->types->find($typeId) === null) {
            throw new MeetingRuleException('Meeting type was not found.');
        }

        return $this->transaction->run(function () use ($typeId, $name, $headings): int {
            $saved = $this->templates->add(new MeetingTemplate(null, $typeId, $name));
            $id = $saved->id();

            if ($id === null) {
                throw new \RuntimeException('The meeting template was not saved.');
            }

            $position = 1;

            foreach ($headings as $title) {
                if (! is_string($title)) {
                    throw new \InvalidArgumentException('A template heading needs a title.');
                }

                $this->items->add(new MeetingTemplateItem(null, $id, $position, $title));
                $position++;
            }

            return $id;
        });
    }

    public function rename(int $templateId, string $name): void
    {
        $this->requireManage();
        $template = $this->requireTemplate($templateId);

        $this->transaction->run(function () use ($template, $name): void {
            $this->templates->save($template->withName($name));
        });
    }

    public function renameHeading(int $templateId, int $itemId, string $title): void
    {
        $this->requireManage();
        $item = $this->requireItem($templateId, $itemId);

        $this->transaction->run(function () use ($item, $title): void {
            $this->items->save($item->withTitle($title));
        });
    }

    public function removeHeading(int $templateId, int $itemId): void
    {
        $this->requireManage();
        $this->requireItem($templateId, $itemId);
        $updated = $this->order->remove($this->asAgenda($this->items->forTemplate($templateId)), $itemId);

        $this->transaction->run(function () use ($itemId, $updated): void {
            $this->items->remove($itemId);
            $this->savePositions($updated);
        });
    }

    public function moveHeading(int $templateId, int $itemId, int $direction): void
    {
        $this->requireManage();
        $this->requireItem($templateId, $itemId);
        $moved = $this->order->move($this->asAgenda($this->items->forTemplate($templateId)), $itemId, $direction);

        $this->transaction->run(function () use ($moved): void {
            $this->savePositions($moved);
        });
    }

    public function addHeading(int $templateId, string $title): int
    {
        $this->requireManage();
        $this->requireTemplate($templateId);

        $saved = $this->transaction->run(function () use ($templateId, $title): MeetingTemplateItem {
            $position = $this->order->nextPosition($this->asAgenda($this->items->forTemplate($templateId)));

            return $this->items->add(new MeetingTemplateItem(null, $templateId, $position, $title));
        });
        $id = $saved->id();

        if ($id === null) {
            throw new \RuntimeException('The template heading was not saved.');
        }

        return $id;
    }

    public function remove(int $templateId): void
    {
        $this->requireManage();
        $this->requireTemplate($templateId);

        $this->transaction->run(function () use ($templateId): void {
            foreach ($this->items->forTemplate($templateId) as $item) {
                $id = $item->id();

                if ($id !== null) {
                    $this->items->remove($id);
                }
            }

            $this->templates->remove($templateId);
        });
    }

    public function assertForType(int $templateId, int $typeId): void
    {
        $this->requireManage();
        $template = $this->requireTemplate($templateId);

        if ($template->typeId() !== $typeId) {
            throw new MeetingRuleException('The template belongs to another meeting type.');
        }
    }

    public function copyOnto(int $meetingId, int $templateId): void
    {
        $this->requireManage();
        $template = $this->requireTemplate($templateId);
        $meeting = $this->meetings->find($meetingId);

        if ($meeting === null) {
            throw new MeetingRuleException('Meeting was not found.');
        }

        if ($template->typeId() !== $meeting->typeId()) {
            throw new MeetingRuleException('The template belongs to another meeting type.');
        }

        if ($meeting->status() !== MeetingStatus::Planned || $this->agenda->forMeeting($meetingId) !== []) {
            throw new MeetingRuleException('A template can be copied only onto a planned meeting with an empty agenda.');
        }

        $headings = $this->items->forTemplate($templateId);
        usort(
            $headings,
            static fn (MeetingTemplateItem $left, MeetingTemplateItem $right): int => $left->position() <=> $right->position()
        );

        $this->transaction->run(function () use ($meetingId, $headings): void {
            $position = 1;

            foreach ($headings as $heading) {
                $this->agenda->add(new AgendaItem(null, $meetingId, $position, $heading->title(), ''));
                $position++;
            }
        });
    }

    /**
     * @return list<MeetingTemplate>
     */
    public function all(): array
    {
        $this->requireManage();

        return $this->templates->all();
    }

    /**
     * @return list<MeetingTemplateItem>
     */
    public function headings(int $templateId): array
    {
        $this->requireManage();
        $this->requireTemplate($templateId);
        $headings = $this->items->forTemplate($templateId);
        usort(
            $headings,
            static fn (MeetingTemplateItem $left, MeetingTemplateItem $right): int => $left->position() <=> $right->position()
        );

        return $headings;
    }

    private function requireManage(): void
    {
        if (! $this->authorizer->allows(Capabilities::MANAGE_MEETINGS)) {
            throw new NotAllowed(Capabilities::MANAGE_MEETINGS);
        }
    }

    private function requireTemplate(int $templateId): MeetingTemplate
    {
        $template = $this->templates->find($templateId);

        if ($template === null) {
            throw new MeetingRuleException('Meeting template was not found.');
        }

        return $template;
    }

    private function requireItem(int $templateId, int $itemId): MeetingTemplateItem
    {
        $item = $this->items->find($itemId);

        if ($item === null || $item->templateId() !== $templateId) {
            throw new MeetingRuleException('Meeting template was not found.');
        }

        return $item;
    }

    /**
     * AgendaOrder matches items by id. The meeting id on these stand-ins is unused.
     *
     * @param list<MeetingTemplateItem> $items
     * @return list<AgendaItem>
     */
    private function asAgenda(array $items): array
    {
        $agenda = [];

        foreach ($items as $item) {
            $agenda[] = new AgendaItem($item->id(), 1, $item->position(), $item->title(), '');
        }

        return $agenda;
    }

    /**
     * @param list<AgendaItem> $items
     */
    private function savePositions(array $items): void
    {
        foreach ($items as $agendaItem) {
            $id = $agendaItem->id();

            if ($id === null) {
                continue;
            }

            $existing = $this->items->find($id);

            if ($existing === null) {
                continue;
            }

            $this->items->save($existing->withPosition($agendaItem->position()));
        }
    }
}
