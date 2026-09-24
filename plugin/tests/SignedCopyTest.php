<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Meeting\SignedCopies;
use Foreningssystem\Application\Meeting\SignedFileStore;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\AuditEvent;
use Foreningssystem\Domain\Meeting\AuditLog;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Domain\Meeting\SignedCopy;
use Foreningssystem\Domain\Meeting\SignedCopyRepository;
use Foreningssystem\Domain\Meeting\SignedCopyType;
use Foreningssystem\Infrastructure\Persistence\SignedCopySchemaMigration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SignedCopyTest extends TestCase
{
    public function test_replacing_a_signed_copy_keeps_the_revision_and_writes_an_audit_event(): void
    {
        $minutes = new MemoryMinutesRepository();
        $minutes->addDocument(4);
        $revision = $minutes->addRevision(new MinutesRevision(
            null,
            1,
            4,
            1,
            RevisionState::Finalized,
            'Föreningen köper modell X.',
            '{"decision":"modell X"}',
            false
        ));
        $files = new MemorySignedFileStore();
        $audit = new MemoryAuditLog();
        $copies = new MemorySignedCopyRepository();
        $signed = $this->service($minutes, $copies, $files, $audit, [
            Capabilities::MANAGE_DOCUMENTS,
            Capabilities::FINALIZE_MINUTES,
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ]);
        $revisionId = (int) $revision->id();
        $pdf = "%PDF-1.4\n1 0 obj\nendobj\n%%EOF";
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 12);

        self::assertSame('attached', $signed->attach($revisionId, $pdf, 7));
        $first = $signed->current($revisionId);
        self::assertNotNull($first);
        self::assertSame(SignedCopyType::PDF, $first->mediaType());
        self::assertNull($first->replacedBy());
        self::assertSame('Föreningen köper modell X.', $minutes->findRevision($revisionId)?->body());

        self::assertSame('replaced', $signed->attach($revisionId, $jpeg, 7));
        self::assertSame($jpeg, $signed->read($revisionId));
        self::assertSame(SignedCopyType::JPEG, $signed->current($revisionId)?->mediaType());
        self::assertSame($pdf, $files->read($first->storageName()));
        self::assertSame($revisionId, $copies->find((int) $first->id())?->revisionId());
        self::assertNotNull($copies->find((int) $first->id())?->replacedBy());
        self::assertSame('Föreningen köper modell X.', $minutes->findRevision($revisionId)?->body());

        $replacement = null;

        foreach ($audit->forObject('minutes_revision', $revisionId) as $event) {
            if ($event->action() === 'replace_signed_copy') {
                $replacement = $event;
            }
        }

        self::assertNotNull($replacement);
        self::assertSame($revisionId, $replacement->objectId());
        self::assertSame(7, $replacement->actorUserId());
        self::assertStringNotContainsString('Föreningen', $replacement->action());
    }

    public function test_a_draft_cannot_receive_a_signed_copy(): void
    {
        $minutes = new MemoryMinutesRepository();
        $minutes->addDocument(4);
        $revision = $minutes->addRevision(new MinutesRevision(
            null,
            1,
            4,
            1,
            RevisionState::Draft,
            'Utkast.',
            '{"draft":true}',
            false
        ));
        $files = new MemorySignedFileStore();
        $signed = $this->service($minutes, new MemorySignedCopyRepository(), $files, new MemoryAuditLog(), [
            Capabilities::MANAGE_DOCUMENTS,
            Capabilities::FINALIZE_MINUTES,
        ]);

        try {
            $signed->attach((int) $revision->id(), "%PDF-1.4\n%%EOF\n", 7);
            self::fail('A draft should not accept a signed copy.');
        } catch (MeetingRuleException) {
        }

        self::assertSame(0, $files->writes);
    }

    public function test_documents_alone_do_not_allow_a_signed_copy(): void
    {
        $minutes = new MemoryMinutesRepository();
        $minutes->addDocument(4);
        $revision = $minutes->addRevision(new MinutesRevision(
            null,
            1,
            4,
            1,
            RevisionState::Finalized,
            'Låst.',
            '{"locked":true}',
            false
        ));
        $signed = $this->service($minutes, new MemorySignedCopyRepository(), new MemorySignedFileStore(), new MemoryAuditLog(), [
            Capabilities::MANAGE_DOCUMENTS,
            Capabilities::RECORD_MEETING,
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ]);

        $this->expectException(NotAllowed::class);
        $signed->attach((int) $revision->id(), "%PDF-1.4\n%%EOF\n", 7);
    }

    public function test_a_text_file_is_not_a_signed_copy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SignedCopyType::fromBytes('Det här är ingen skanning.');
    }

    public function test_schema_migration_stores_signed_copies_and_audit_events(): void
    {
        $migration = new SignedCopySchemaMigration('wp_', '');
        $sql = $migration->statements();

        self::assertSame(10, $migration->version());
        self::assertStringContainsString('CREATE TABLE wp_assoc_signed_copy', $sql);
        self::assertStringContainsString('CREATE TABLE wp_assoc_audit_event', $sql);
        self::assertStringContainsString('replaced_by', $sql);
        self::assertStringNotContainsString('wp_users', $sql);
    }

    /**
     * @param list<string> $capabilities
     */
    private function service(
        MemoryMinutesRepository $minutes,
        SignedCopyRepository $copies,
        SignedFileStore $files,
        AuditLog $audit,
        array $capabilities,
    ): SignedCopies {
        return new SignedCopies(
            $minutes,
            $copies,
            $files,
            $audit,
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

final class MemorySignedCopyRepository implements SignedCopyRepository
{
    /** @var array<int, SignedCopy> */
    private array $copies = [];

    private int $nextId = 1;

    public function add(int $revisionId, string $mediaType, string $storageName): SignedCopy
    {
        $saved = (new SignedCopy(null, $revisionId, $mediaType, $storageName, null))->withId($this->nextId);
        $this->copies[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function markReplaced(int $id, int $replacedBy): void
    {
        $copy = $this->copies[$id] ?? null;

        if (! $copy instanceof SignedCopy) {
            throw new \RuntimeException('Signed copy was not found.');
        }

        $this->copies[$id] = $copy->replacedByCopy($replacedBy);
    }

    public function currentForRevision(int $revisionId): ?SignedCopy
    {
        $found = null;

        foreach ($this->copies as $copy) {
            if ($copy->revisionId() === $revisionId && $copy->replacedBy() === null) {
                $found = $copy;
            }
        }

        return $found;
    }

    public function find(int $id): ?SignedCopy
    {
        return $this->copies[$id] ?? null;
    }
}

final class MemorySignedFileStore implements SignedFileStore
{
    public int $writes = 0;

    /** @var array<string, string> */
    private array $files = [];

    public function put(string $name, string $bytes): void
    {
        $this->writes++;
        $this->files[$name] = $bytes;
    }

    public function read(string $name): string
    {
        if (! isset($this->files[$name])) {
            throw new \RuntimeException('The signed copy was not found.');
        }

        return $this->files[$name];
    }

    public function discard(string $name): void
    {
        unset($this->files[$name]);
    }
}

final class MemoryAuditLog implements AuditLog
{
    /** @var list<AuditEvent> */
    private array $events = [];

    public function record(string $objectType, int $objectId, string $action, int $actorUserId): void
    {
        $this->events[] = new AuditEvent(count($this->events) + 1, $objectType, $objectId, $action, $actorUserId, '2024-05-02 18:00:00');
    }

    public function forObject(string $objectType, int $objectId): array
    {
        $rows = [];

        foreach ($this->events as $event) {
            if ($event->objectType() === $objectType && $event->objectId() === $objectId) {
                $rows[] = $event;
            }
        }

        return $rows;
    }

    public function recordAt(string $objectType, int $objectId, string $action, int $actorUserId, string $createdAt): void
    {
        $this->events[] = new AuditEvent(count($this->events) + 1, $objectType, $objectId, $action, $actorUserId, $createdAt);
    }

    public function forgetOnOrBefore(string $day): int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            throw new \InvalidArgumentException('Retention day must use YYYY-MM-DD.');
        }

        $kept = [];
        $removed = 0;

        foreach ($this->events as $event) {
            if (substr($event->createdAt(), 0, 10) <= $day) {
                $removed++;

                continue;
            }

            $kept[] = $event;
        }

        $this->events = $kept;

        return $removed;
    }
}
