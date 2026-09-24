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

    /**
     * AgendaOrder only needs positions. The meeting id on these stand-ins is unused.
     *
     * @param list<MeetingTemplateItem> $items
     * @return list<AgendaItem>
     */
    private function asAgenda(array $items): array
    {
        $agenda = [];

        foreach ($items as $item) {
            $agenda[] = new AgendaItem(null, 1, $item->position(), $item->title(), '');
        }

        return $agenda;
    }
}
