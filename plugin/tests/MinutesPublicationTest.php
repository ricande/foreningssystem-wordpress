<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Meeting\MinutesPublication;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\PublicationVisibility;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Infrastructure\Persistence\MinutesPublicationSchemaMigration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MinutesPublicationTest extends TestCase
{
    public function test_publishing_shows_the_locked_text_and_leaves_it_unchanged(): void
    {
        $meetings = new MemoryMeetingRepository();
        $minutes = new MemoryMinutesRepository();
        $meeting = $meetings->add(new Meeting(null, 1, 'Tidigt möte', MeetingMoment::fromLocal('2024-05-02 18:00'), 'Lokalen', MeetingStatus::Held));
        $revision = $this->revision($minutes, (int) $meeting->id(), 1, 'Offentlig text');
        $service = $this->service([Capabilities::PUBLISH_MINUTES], $meetings, $minutes);

        $service->publish((int) $revision->id());
        $stored = $minutes->findRevision((int) $revision->id());
        $latest = $service->latest();

        self::assertNotNull($stored);
        self::assertSame('Offentlig text', $stored->body());
        self::assertSame(PublicationVisibility::Public, $stored->visibility());
        self::assertNotNull($latest);
        self::assertSame('Tidigt möte', $latest->meeting()->title());
        self::assertSame('Offentlig text', $latest->revision()->body());
        self::assertSame('{"source":"snapshot"}', $stored->payload());
    }

    public function test_a_later_published_meeting_is_the_one_shown(): void
    {
        $meetings = new MemoryMeetingRepository();
        $minutes = new MemoryMinutesRepository();
        $early = $meetings->add(new Meeting(null, 1, 'Tidigt möte', MeetingMoment::fromLocal('2024-05-02 18:00'), '', MeetingStatus::Held));
        $late = $meetings->add(new Meeting(null, 1, 'Sent möte', MeetingMoment::fromLocal('2024-06-02 18:00'), '', MeetingStatus::Held));
        $earlyRevision = $this->revision($minutes, (int) $early->id(), 1, 'Tidig text');
        $lateRevision = $this->revision($minutes, (int) $late->id(), 1, 'Sen text');
        $service = $this->service([Capabilities::PUBLISH_MINUTES], $meetings, $minutes);
        $service->publish((int) $earlyRevision->id());
        $service->publish((int) $lateRevision->id());

        $latest = $service->latest();

        self::assertNotNull($latest);
        self::assertSame('Sen text', $latest->revision()->body());
    }

    public function test_unpublishing_hides_the_revision(): void
    {
        $meetings = new MemoryMeetingRepository();
        $minutes = new MemoryMinutesRepository();
        $meeting = $meetings->add(new Meeting(null, 1, 'Tidigt möte', MeetingMoment::fromLocal('2024-05-02 18:00'), '', MeetingStatus::Held));
        $revision = $this->revision($minutes, (int) $meeting->id(), 1, 'Offentlig text');
        $service = $this->service([Capabilities::PUBLISH_MINUTES], $meetings, $minutes);
        $service->publish((int) $revision->id());
        $service->unpublish((int) $revision->id());

        $stored = $minutes->findRevision((int) $revision->id());

        self::assertNotNull($stored);
        self::assertSame(PublicationVisibility::Board, $stored->visibility());
        self::assertSame('Offentlig text', $stored->body());
        self::assertNull($service->latest());
    }

    public function test_a_superseded_revision_leaves_the_public_site(): void
    {
        $meetings = new MemoryMeetingRepository();
        $minutes = new MemoryMinutesRepository();
        $meeting = $meetings->add(new Meeting(null, 1, 'Tidigt möte', MeetingMoment::fromLocal('2024-05-02 18:00'), '', MeetingStatus::Held));
        $revision = $this->revision($minutes, (int) $meeting->id(), 1, 'Offentlig text');
        $service = $this->service([Capabilities::PUBLISH_MINUTES], $meetings, $minutes);
        $service->publish((int) $revision->id());
        $stored = $minutes->findRevision((int) $revision->id());
        self::assertNotNull($stored);
        $minutes->saveRevision($stored->markedSuperseded(2));

        $hidden = $minutes->findRevision((int) $revision->id());

        self::assertNotNull($hidden);
        self::assertSame(PublicationVisibility::Board, $hidden->visibility());
        self::assertSame('Offentlig text', $hidden->body());
        self::assertSame(2, $hidden->supersededBy());
        self::assertNull($service->latest());
    }

    public function test_a_draft_cannot_be_published(): void
    {
        $meetings = new MemoryMeetingRepository();
        $minutes = new MemoryMinutesRepository();
        $meeting = $meetings->add(new Meeting(null, 1, 'Tidigt möte', MeetingMoment::fromLocal('2024-05-02 18:00'), '', MeetingStatus::Held));
        $minutesId = $minutes->addDocument((int) $meeting->id());
        $draft = $minutes->addRevision(new MinutesRevision(null, $minutesId, (int) $meeting->id(), 1, RevisionState::Draft, 'Utkast', '{"source":"snapshot"}', false));
        $service = $this->service([Capabilities::PUBLISH_MINUTES], $meetings, $minutes);

        $this->expectException(MeetingRuleException::class);
        $service->publish((int) $draft->id());
    }

    public function test_finalizing_does_not_allow_publishing(): void
    {
        $meetings = new MemoryMeetingRepository();
        $minutes = new MemoryMinutesRepository();
        $meeting = $meetings->add(new Meeting(null, 1, 'Tidigt möte', MeetingMoment::fromLocal('2024-05-02 18:00'), '', MeetingStatus::Held));
        $revision = $this->revision($minutes, (int) $meeting->id(), 1, 'Offentlig text');
        $service = $this->service([Capabilities::FINALIZE_MINUTES], $meetings, $minutes);

        $this->expectException(NotAllowed::class);
        $service->publish((int) $revision->id());
    }

    public function test_reading_the_public_text_does_not_require_a_capability(): void
    {
        $meetings = new MemoryMeetingRepository();
        $minutes = new MemoryMinutesRepository();
        $meeting = $meetings->add(new Meeting(null, 1, 'Tidigt möte', MeetingMoment::fromLocal('2024-05-02 18:00'), '', MeetingStatus::Held));
        $revision = $this->revision($minutes, (int) $meeting->id(), 1, 'Offentlig text');
        $this->service([Capabilities::PUBLISH_MINUTES], $meetings, $minutes)->publish((int) $revision->id());
        $reader = $this->service([], $meetings, $minutes);

        $latest = $reader->latest();

        self::assertNotNull($latest);
        self::assertSame('Offentlig text', $latest->revision()->body());
    }

    public function test_a_draft_cannot_be_marked_public(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MinutesRevision(1, 1, 1, 1, RevisionState::Draft, 'Utkast', '{"source":"snapshot"}', false, null, null, PublicationVisibility::Public);
    }

    public function test_schema_migration_stores_publication_visibility(): void
    {
        $migration = new MinutesPublicationSchemaMigration('wp_', '');
        $sql = $migration->statements();

        self::assertSame(11, $migration->version());
        self::assertStringContainsString('visibility varchar(32) NOT NULL default \'board\'', $sql);
        self::assertStringNotContainsString('wp_users', $sql);
    }

    private function revision(MemoryMinutesRepository $minutes, int $meetingId, int $number, string $body): MinutesRevision
    {
        $minutesId = $minutes->findDocumentId($meetingId) ?? $minutes->addDocument($meetingId);

        return $minutes->addRevision(new MinutesRevision(
            null,
            $minutesId,
            $meetingId,
            $number,
            RevisionState::Finalized,
            $body,
            '{"source":"snapshot"}',
            false
        ));
    }

    /**
     * @param list<string> $capabilities
     */
    private function service(array $capabilities, MemoryMeetingRepository $meetings, MemoryMinutesRepository $minutes): MinutesPublication
    {
        return new MinutesPublication(
            $meetings,
            $minutes,
            new class ($capabilities) implements Authorizer {
                /** @param list<string> $capabilities */
                public function __construct(private array $capabilities)
                {
                }

                public function allows(string $capability): bool
                {
                    return in_array($capability, $this->capabilities, true);
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
