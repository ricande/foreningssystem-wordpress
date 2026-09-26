<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Meeting\SignedCopies;
use Foreningssystem\Application\Meeting\SignedCopyBusy;
use Foreningssystem\Application\Meeting\SignedCopyLock;
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

    public function test_a_second_upload_during_the_first_cannot_make_two_copies_current(): void
    {
        $minutes = new MemoryMinutesRepository();
        $revisionId = $this->finalizedRevision($minutes);
        $files = new MemorySignedFileStore();
        $copies = new MemorySignedCopyRepository();
        $lock = new MemorySignedCopyLock();
        $signed = $this->service($minutes, $copies, $files, new MemoryAuditLog(), [
            Capabilities::MANAGE_DOCUMENTS,
            Capabilities::FINALIZE_MINUTES,
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ], $lock);
        $first = "%PDF-1.4\n1 0 obj\nendobj\n%%EOF";
        $second = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 12);
        $refused = null;

        // The second officer uploads while the first upload sits between reading the
        // current copy and saving its own row. That interleaving used to leave both rows
        // current, because each upload only replaced the copy it had read before.
        $copies->beforeAdd = static function () use ($signed, $revisionId, $second, &$refused): void {
            try {
                $signed->attach($revisionId, $second, 8);
            } catch (\Throwable $error) {
                $refused = $error;
            }
        };

        self::assertSame('attached', $signed->attach($revisionId, $first, 7));
        self::assertInstanceOf(SignedCopyBusy::class, $refused);
        self::assertSame(1, $copies->currentCount($revisionId));
        self::assertSame($first, $signed->read($revisionId));
        self::assertSame(1, $files->writes);
        self::assertSame(1, $lock->maxHeld);
        self::assertSame(0, $lock->held($revisionId));
    }

    public function test_an_upload_takes_over_from_every_copy_that_is_still_current(): void
    {
        $minutes = new MemoryMinutesRepository();
        $revisionId = $this->finalizedRevision($minutes);
        $files = new MemorySignedFileStore();
        $copies = new MemorySignedCopyRepository();
        $stale = $copies->add($revisionId, SignedCopyType::PDF, 'signed-' . $revisionId . '-' . str_repeat('a', 64) . '.pdf');
        $alsoStale = $copies->add($revisionId, SignedCopyType::PDF, 'signed-' . $revisionId . '-' . str_repeat('b', 64) . '.pdf');
        $signed = $this->service($minutes, $copies, $files, new MemoryAuditLog(), [
            Capabilities::MANAGE_DOCUMENTS,
            Capabilities::FINALIZE_MINUTES,
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ]);

        self::assertSame(2, $copies->currentCount($revisionId));
        self::assertSame('replaced', $signed->attach($revisionId, "%PDF-1.4\n1 0 obj\nendobj\n%%EOF", 7));

        $current = $signed->current($revisionId);
        self::assertNotNull($current);
        self::assertSame(1, $copies->currentCount($revisionId));
        self::assertSame($current->id(), $copies->find((int) $stale->id())?->replacedBy());
        self::assertSame($current->id(), $copies->find((int) $alsoStale->id())?->replacedBy());
    }

    public function test_a_failed_upload_releases_the_revision_and_leaves_no_file(): void
    {
        $minutes = new MemoryMinutesRepository();
        $revisionId = $this->finalizedRevision($minutes);
        $files = new MemorySignedFileStore();
        $copies = new MemorySignedCopyRepository();
        $lock = new MemorySignedCopyLock();
        $signed = $this->service($minutes, $copies, $files, new MemoryAuditLog(), [
            Capabilities::MANAGE_DOCUMENTS,
            Capabilities::FINALIZE_MINUTES,
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ], $lock);
        $copies->beforeAdd = static function (): void {
            throw new \RuntimeException('The signed copy could not be saved.');
        };

        try {
            $signed->attach($revisionId, "%PDF-1.4\n1 0 obj\nendobj\n%%EOF", 7);
            self::fail('A repository failure should stop the upload.');
        } catch (\RuntimeException $error) {
            self::assertNotInstanceOf(SignedCopyBusy::class, $error);
        }

        self::assertSame(0, $lock->held($revisionId));
        self::assertSame(0, $copies->currentCount($revisionId));
        self::assertSame(1, $files->writes);
        self::assertSame([], $files->names());

        self::assertSame('attached', $signed->attach($revisionId, "%PDF-1.4\n1 0 obj\nendobj\n%%EOF", 7));
        self::assertSame(2, $lock->acquired);
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

    private function finalizedRevision(MemoryMinutesRepository $minutes): int
    {
        $minutes->addDocument(4);

        return (int) $minutes->addRevision(new MinutesRevision(
            null,
            1,
            4,
            1,
            RevisionState::Finalized,
            'Föreningen köper modell X.',
            '{"decision":"modell X"}',
            false
        ))->id();
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
        ?SignedCopyLock $lock = null,
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
            },
            $lock ?? new MemorySignedCopyLock()
        );
    }
}

final class MemorySignedCopyRepository implements SignedCopyRepository
{
    /** Runs once inside the next add(), so a test can interleave a second upload. */
    public ?\Closure $beforeAdd = null;

    /** @var array<int, SignedCopy> */
    private array $copies = [];

    private int $nextId = 1;

    public function add(int $revisionId, string $mediaType, string $storageName): SignedCopy
    {
        $interleave = $this->beforeAdd;
        $this->beforeAdd = null;

        if ($interleave instanceof \Closure) {
            $interleave();
        }

        $saved = (new SignedCopy(null, $revisionId, $mediaType, $storageName, null))->withId($this->nextId);
        $this->copies[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function replaceCurrent(int $revisionId, int $replacedBy): int
    {
        $replaced = 0;

        foreach ($this->copies as $id => $copy) {
            if ($copy->revisionId() !== $revisionId || $copy->replacedBy() !== null || $id === $replacedBy) {
                continue;
            }

            $this->copies[$id] = $copy->replacedByCopy($replacedBy);
            $replaced++;
        }

        return $replaced;
    }

    public function currentCount(int $revisionId): int
    {
        $current = 0;

        foreach ($this->copies as $copy) {
            if ($copy->revisionId() === $revisionId && $copy->replacedBy() === null) {
                $current++;
            }
        }

        return $current;
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

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->files);
    }
}

final class MemorySignedCopyLock implements SignedCopyLock
{
    public int $acquired = 0;

    public int $maxHeld = 0;

    /** @var array<int, int> */
    private array $holders = [];

    public function acquire(int $revisionId): void
    {
        if (($this->holders[$revisionId] ?? 0) > 0) {
            throw new SignedCopyBusy('Another signed copy upload holds this revision.');
        }

        $this->holders[$revisionId] = 1;
        $this->acquired++;
        $this->maxHeld = max($this->maxHeld, array_sum($this->holders));
    }

    public function release(int $revisionId): void
    {
        $this->holders[$revisionId] = 0;
    }

    public function held(int $revisionId): int
    {
        return $this->holders[$revisionId] ?? 0;
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
