<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Meeting\MeetingStarterCatalog;
use Foreningssystem\Application\Meeting\MeetingTemplates;
use Foreningssystem\Domain\Meeting\AgendaItem;
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

    public function test_an_edited_association_template_can_be_saved_and_opened_again(): void
    {
        $fixture = $this->fixture();
        $starter = MeetingStarterCatalog::find('annual');
        self::assertNotNull($starter);
        $templateId = $fixture['service']->createFromStarter('annual', 'Årsmöte', $fixture['board'], $starter->headings());
        $fixture['service']->rename($templateId, 'Årsmöte enligt våra stadgar');
        $first = $fixture['service']->headings($templateId)[0];
        $fixture['service']->renameHeading($templateId, (int) $first->id(), 'Stadgeenlig öppning');
        $fixture['service']->addHeading($templateId, 'Motioner');
        $added = $fixture['service']->headings($templateId);
        $fixture['service']->moveHeading($templateId, (int) $added[count($added) - 1]->id(), -1);
        $report = 0;

        foreach ($fixture['service']->headings($templateId) as $heading) {
            if ($heading->title() === 'Financial report') {
                $report = (int) $heading->id();
            }
        }

        $fixture['service']->removeHeading($templateId, $report);
        $opened = null;

        foreach ($fixture['service']->all() as $template) {
            if ($template->id() === $templateId) {
                $opened = $template;
            }
        }

        self::assertNotNull($opened);
        self::assertSame('Årsmöte enligt våra stadgar', $opened->name());
        self::assertSame($fixture['annual'], $opened->typeId());
        $titles = array_map(static fn (MeetingTemplateItem $item): string => $item->title(), $fixture['service']->headings($templateId));
        self::assertSame('Stadgeenlig öppning', $titles[0]);
        self::assertNotContains('Financial report', $titles);
        self::assertSame(
            array_search('Close of the meeting', $titles, true),
            array_search('Motioner', $titles, true) + 1
        );
    }

    public function test_checked_annual_items_are_saved_and_the_original_list_stays_complete(): void
    {
        $starter = MeetingStarterCatalog::find('annual');
        self::assertNotNull($starter);
        self::assertCount(24, $starter->headings());
        self::assertSame('Opening of the meeting', $starter->headings()[0]);
        self::assertSame('Adoption of the voting list', $starter->headings()[1]);
        self::assertSame('Election of the meeting chair', $starter->headings()[2]);
        self::assertSame('Close of the meeting', $starter->headings()[23]);
        $fixture = $this->fixture();
        $kept = [$starter->headings()[0], $starter->headings()[7], $starter->headings()[23]];
        $templateId = $fixture['service']->createFromStarter('annual', 'Årsmöte enligt våra stadgar', $fixture['board'], $kept);

        self::assertSame($kept, array_map(
            static fn (MeetingTemplateItem $item): string => $item->title(),
            $fixture['service']->headings($templateId)
        ));
        self::assertSame($starter->headings(), MeetingStarterCatalog::find('annual')?->headings());
    }

    public function test_two_meetings_from_one_saved_template_stay_independent(): void
    {
        $fixture = $this->fixture();
        $starter = MeetingStarterCatalog::find('annual');
        self::assertNotNull($starter);
        $templateId = $fixture['service']->createFromStarter('annual', 'Årsmöte enligt våra stadgar', $fixture['board'], $starter->headings());
        $first = $this->plan($fixture['meetings'], $fixture['annual'], 'Årsmöte 2027', '2027-03-15 18:00');
        $second = $this->plan($fixture['meetings'], $fixture['annual'], 'Årsmöte 2028', '2028-03-20 18:00');
        $fixture['service']->copyOnto($first, $templateId);
        $fixture['service']->copyOnto($second, $templateId);
        $item = $fixture['agenda']->forMeeting($first)[0];
        $fixture['agenda']->save(new AgendaItem(
            $item->id(),
            $item->meetingId(),
            $item->position(),
            'Ändrad bara 2027',
            $item->numberOverride()
        ));
        $fixture['service']->renameHeading(
            $templateId,
            (int) $fixture['service']->headings($templateId)[1]->id(),
            'Ny mallrubrik'
        );

        self::assertSame('Ändrad bara 2027', $fixture['agenda']->forMeeting($first)[0]->title());
        self::assertSame('Adoption of the voting list', $fixture['agenda']->forMeeting($first)[1]->title());
        self::assertSame(
            ['Opening of the meeting', 'Adoption of the voting list'],
            array_map(static fn (AgendaItem $agendaItem): string => $agendaItem->title(), array_slice($fixture['agenda']->forMeeting($second), 0, 2))
        );
        self::assertSame('Opening of the meeting', $fixture['service']->headings($templateId)[0]->title());
        self::assertSame('Ny mallrubrik', $fixture['service']->headings($templateId)[1]->title());
        self::assertSame($starter->headings(), MeetingStarterCatalog::find('annual')?->headings());
    }

    public function test_an_empty_starter_becomes_a_saved_template_that_can_be_reused(): void
    {
        $fixture = $this->fixture();
        $templateId = $fixture['service']->createFromStarter('empty', 'Egen dagordning', $fixture['board'], []);
        self::assertSame([], $fixture['service']->headings($templateId));
        $fixture['service']->addHeading($templateId, 'Egen punkt');
        $fixture['service']->addHeading($templateId, 'Nästa punkt');
        $fixture['service']->rename($templateId, 'Vår egen mall');
        $opened = null;

        foreach ($fixture['service']->all() as $template) {
            if ($template->id() === $templateId) {
                $opened = $template;
            }
        }

        self::assertNotNull($opened);
        self::assertSame('Vår egen mall', $opened->name());
        self::assertSame($fixture['board'], $opened->typeId());
        $first = $this->plan($fixture['meetings'], $fixture['board'], 'Arbetsmöte ett', '2027-04-01 18:00');
        $second = $this->plan($fixture['meetings'], $fixture['board'], 'Arbetsmöte två', '2027-05-01 18:00');
        $fixture['service']->copyOnto($first, $templateId);
        $fixture['service']->copyOnto($second, $templateId);
        $fixture['service']->renameHeading($templateId, (int) $fixture['service']->headings($templateId)[0]->id(), 'Ändrad mall');

        self::assertSame(['Egen punkt', 'Nästa punkt'], array_map(
            static fn (AgendaItem $item): string => $item->title(),
            $fixture['agenda']->forMeeting($first)
        ));
        self::assertSame(['Egen punkt', 'Nästa punkt'], array_map(
            static fn (AgendaItem $item): string => $item->title(),
            $fixture['agenda']->forMeeting($second)
        ));
        self::assertSame('Ändrad mall', $fixture['service']->headings($templateId)[0]->title());
    }

    public function test_a_saved_template_does_not_follow_a_later_copy_of_the_same_starter(): void
    {
        $fixture = $this->fixture();
        $before = MeetingStarterCatalog::find('association')?->headings();
        self::assertIsArray($before);
        $saved = $fixture['service']->createFromStarter('association', 'Föreningsmöte enligt våra stadgar', $fixture['annual'], $before);
        $fixture['service']->renameHeading($saved, (int) $fixture['service']->headings($saved)[0]->id(), 'Vår öppning');
        $later = $fixture['service']->createFromStarter('association', 'Föreningsmöte igen', $fixture['annual'], $before);

        self::assertSame($before, MeetingStarterCatalog::find('association')?->headings());
        self::assertSame($fixture['members'], $this->typeOf($fixture, $saved));
        self::assertSame($fixture['members'], $this->typeOf($fixture, $later));
        self::assertSame('Vår öppning', $fixture['service']->headings($saved)[0]->title());
        self::assertSame($before, array_map(
            static fn (MeetingTemplateItem $item): string => $item->title(),
            $fixture['service']->headings($later)
        ));
        self::assertSame(['annual', 'board', 'association', 'empty'], array_map(
            static fn ($starter): string => $starter->key(),
            MeetingStarterCatalog::all()
        ));
        self::assertSame([], MeetingStarterCatalog::find('empty')?->headings());
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
        self::assertStringNotContainsString('starter', $sql);
    }

    private function fixture(): array
    {
        $types = new MemoryMeetingTypeRepository();
        $meetings = new MemoryMeetingRepository();
        $agenda = new MemoryAgendaRepository();
        $annual = (int) $types->add(new MeetingType(null, 'annual_meeting', 'Årsmöte', 20))->id();
        $board = (int) $types->add(new MeetingType(null, 'board_meeting', 'Styrelsemöte', 10))->id();
        $members = (int) $types->add(new MeetingType(null, 'member_meeting', 'Medlemsmöte', 40))->id();

        return [
            'service' => $this->service(
                $types,
                $meetings,
                $agenda,
                new MemoryMeetingTemplateRepository(),
                new MemoryMeetingTemplateItemRepository(),
                true
            ),
            'meetings' => $meetings,
            'agenda' => $agenda,
            'annual' => $annual,
            'board' => $board,
            'members' => $members,
        ];
    }

    private function plan(MemoryMeetingRepository $meetings, int $typeId, string $title, string $when): int
    {
        return (int) $meetings->add(new Meeting(
            null,
            $typeId,
            $title,
            MeetingMoment::fromLocal($when),
            'Lokalen',
            MeetingStatus::Planned
        ))->id();
    }

    /**
     * @param array{service: MeetingTemplates, members: int} $fixture
     */
    private function typeOf(array $fixture, int $templateId): int
    {
        foreach ($fixture['service']->all() as $template) {
            if ($template->id() === $templateId) {
                return $template->typeId();
            }
        }

        return 0;
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

    public function save(MeetingTemplate $template): void
    {
        $id = $template->id();

        if ($id === null || ! isset($this->templates[$id])) {
            throw new \RuntimeException('Meeting template was not found.');
        }

        $this->templates[$id] = $template;
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

    public function save(MeetingTemplateItem $item): void
    {
        $id = $item->id();

        if ($id === null || ! isset($this->items[$id])) {
            throw new \RuntimeException('Meeting template item was not found.');
        }

        $this->items[$id] = $item;
    }

    public function remove(int $id): void
    {
        unset($this->items[$id]);
    }

    public function find(int $id): ?MeetingTemplateItem
    {
        return $this->items[$id] ?? null;
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
