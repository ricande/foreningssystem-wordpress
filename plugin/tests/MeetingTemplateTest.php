<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Meeting\MeetingTemplates;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\AgendaOrder;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MeetingTemplate;
use Foreningssystem\Domain\Meeting\MeetingTemplateItem;
use Foreningssystem\Domain\Meeting\MeetingTemplateItemRepository;
use Foreningssystem\Domain\Meeting\MeetingTemplateRepository;
use Foreningssystem\Domain\Meeting\MeetingType;
use Foreningssystem\Infrastructure\Persistence\MeetingTemplateSchemaMigration;
use PHPUnit\Framework\TestCase;

final class MeetingTemplateTest extends TestCase
{
    public function test_a_template_is_copied_once_and_later_edits_stay_on_the_template(): void
    {
        $types = new MemoryMeetingTypeRepository();
        $meetings = new MemoryMeetingRepository();
        $agenda = new MemoryAgendaRepository();
        $templates = new MemoryMeetingTemplateRepository();
        $items = new MemoryMeetingTemplateItemRepository();
        $service = $this->service($types, $meetings, $agenda, $templates, $items, true);
        $board = (int) $types->add(new MeetingType(null, 'board_meeting', 'Styrelsemöte', 10))->id();
        $members = (int) $types->add(new MeetingType(null, 'member_meeting', 'Medlemsmöte', 40))->id();
        $templateId = $service->create($board, 'Ordinarie styrelse');
        $service->addHeading($templateId, 'Mötets öppnande');
        $service->addHeading($templateId, 'Nästa möte');
        $meetingId = (int) $meetings->add(new Meeting(
            null,
            $board,
            'Maj',
            MeetingMoment::fromLocal('2024-05-02 18:00'),
            'Lokalen',
            MeetingStatus::Planned
        ))->id();

        $service->copyOnto($meetingId, $templateId);
        $service->addHeading($templateId, 'Övriga frågor');
        $copied = array_map(static fn ($item) => $item->title(), $agenda->forMeeting($meetingId));

        self::assertSame(['Mötets öppnande', 'Nästa möte'], $copied);
        self::assertSame('', $agenda->forMeeting($meetingId)[0]->numberOverride());
        self::assertCount(3, $service->headings($templateId));

        $wrong = (int) $meetings->add(new Meeting(
            null,
            $members,
            'Medlemmar',
            MeetingMoment::fromLocal('2024-06-01 18:00'),
            '',
            MeetingStatus::Planned
        ))->id();
        $held = (int) $meetings->add(new Meeting(
            null,
            $board,
            'Hållet',
            MeetingMoment::fromLocal('2024-04-01 18:00'),
            '',
            MeetingStatus::Held
        ))->id();

        try {
            $service->copyOnto($wrong, $templateId);
            self::fail('A template from another meeting type was copied.');
        } catch (MeetingRuleException) {
            self::assertSame([], $agenda->forMeeting($wrong));
        }

        try {
            $service->copyOnto($held, $templateId);
            self::fail('A template was copied onto a held meeting.');
        } catch (MeetingRuleException) {
            self::assertSame([], $agenda->forMeeting($held));
        }

        $service->remove($templateId);

        self::assertNull($templates->find($templateId));
        self::assertSame(['Mötets öppnande', 'Nästa möte'], array_map(static fn ($item) => $item->title(), $agenda->forMeeting($meetingId)));
    }

    public function test_templates_are_configuration_and_not_a_hardcoded_annual_agenda(): void
    {
        $migration = new MeetingTemplateSchemaMigration('wp_', '');
        $sql = $migration->statements();

        self::assertSame(13, $migration->version());
        self::assertStringContainsString('CREATE TABLE wp_assoc_meeting_template ', $sql);
        self::assertStringContainsString('CREATE TABLE wp_assoc_meeting_template_item ', $sql);
        self::assertStringContainsString('PRIMARY KEY  (id)', $sql);
        self::assertStringNotContainsString('årsmöte', $sql);
    }

    public function test_planning_a_template_requires_manage_meetings(): void
    {
        $service = $this->service(
            new MemoryMeetingTypeRepository(),
            new MemoryMeetingRepository(),
            new MemoryAgendaRepository(),
            new MemoryMeetingTemplateRepository(),
            new MemoryMeetingTemplateItemRepository(),
            false
        );

        $this->expectException(NotAllowed::class);
        $service->create(1, 'Mall');
    }

    private function service(
        MemoryMeetingTypeRepository $types,
        MemoryMeetingRepository $meetings,
        MemoryAgendaRepository $agenda,
        MemoryMeetingTemplateRepository $templates,
        MemoryMeetingTemplateItemRepository $items,
        bool $allowed,
    ): MeetingTemplates {
        return new MeetingTemplates(
            $types,
            $meetings,
            $agenda,
            $templates,
            $items,
            new AgendaOrder(),
            new class ($allowed) implements Authorizer {
                public function __construct(private bool $allowed)
                {
                }

                public function allows(string $capability): bool
                {
                    return $this->allowed && $capability === Capabilities::MANAGE_MEETINGS;
                }
            },
            new class implements Transaction {
                public function run(callable $callback): mixed
                {
                    return $callback();
                }
            }
        );
    }
}

final class MemoryMeetingTemplateRepository implements MeetingTemplateRepository
{
    /** @var array<int, MeetingTemplate> */
    public array $templates = [];

    private int $nextId = 1;

    public function add(MeetingTemplate $template): MeetingTemplate
    {
        $saved = $template->withId($this->nextId);
        $this->templates[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function remove(int $id): void
    {
        unset($this->templates[$id]);
    }

    public function find(int $id): ?MeetingTemplate
    {
        return $this->templates[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->templates);
    }
}

final class MemoryMeetingTemplateItemRepository implements MeetingTemplateItemRepository
{
    /** @var array<int, MeetingTemplateItem> */
    public array $items = [];

    private int $nextId = 1;

    public function add(MeetingTemplateItem $item): MeetingTemplateItem
    {
        $saved = $item->withId($this->nextId);
        $this->items[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function remove(int $id): void
    {
        unset($this->items[$id]);
    }

    public function forTemplate(int $templateId): array
    {
        $rows = [];

        foreach ($this->items as $item) {
            if ($item->templateId() === $templateId) {
                $rows[] = $item;
            }
        }

        return $rows;
    }
}
