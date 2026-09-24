<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Document\DocumentArchive;
use Foreningssystem\Application\Document\DocumentFileStore;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Document\AssociationDocument;
use Foreningssystem\Domain\Document\DocumentRepository;
use Foreningssystem\Domain\Document\DocumentVisibility;
use Foreningssystem\Infrastructure\Persistence\DocumentSchemaMigration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DocumentArchiveTest extends TestCase
{
    public function test_only_public_documents_are_listed_and_the_file_stays_behind_a_check(): void
    {
        $documents = new MemoryDocumentRepository();
        $files = new MemoryDocumentFileStore();
        $pdf = "%PDF-1.4\n1 0 obj\nendobj\n%%EOF";
        $archive = $this->archive([Capabilities::MANAGE_DOCUMENTS], $documents, $files);
        $publicId = $archive->add('Stadgar <2024>', $pdf, DocumentVisibility::Public);
        $boardId = $archive->add('Intern budget', $pdf . ' ', DocumentVisibility::Board);

        $titles = array_map(static fn ($document): string => $document->title(), $archive->publicList());
        $reader = $this->archive([], $documents, $files);
        $boardReader = $this->archive([Capabilities::VIEW_BOARD_DOCUMENTS], $documents, $files);

        self::assertSame(['Stadgar <2024>'], $titles);
        self::assertSame($pdf, $reader->read($publicId));
        self::assertSame($pdf . ' ', $boardReader->read($boardId));
        self::assertStringNotContainsString('document-', implode(' ', $titles));

        $this->expectException(NotAllowed::class);
        $reader->read($boardId);
    }

    public function test_changing_visibility_does_not_change_the_file(): void
    {
        $documents = new MemoryDocumentRepository();
        $files = new MemoryDocumentFileStore();
        $pdf = "%PDF-1.4\n1 0 obj\nendobj\n%%EOF";
        $archive = $this->archive([Capabilities::MANAGE_DOCUMENTS], $documents, $files);
        $id = $archive->add('Stadgar', $pdf, DocumentVisibility::Board);
        $archive->setVisibility($id, DocumentVisibility::Public);

        self::assertSame(['Stadgar'], array_map(static fn ($document): string => $document->title(), $archive->publicList()));
        self::assertSame($pdf, $archive->read($id));
        self::assertSame(1, $files->writes);
    }

    public function test_viewing_the_board_does_not_allow_an_upload(): void
    {
        $archive = $this->archive([Capabilities::VIEW_BOARD_DOCUMENTS], new MemoryDocumentRepository(), new MemoryDocumentFileStore());

        $this->expectException(NotAllowed::class);
        $archive->add('Stadgar', "%PDF-1.4\n1 0 obj\nendobj\n%%EOF", DocumentVisibility::Public);
    }

    public function test_a_text_file_is_not_a_document(): void
    {
        $archive = $this->archive([Capabilities::MANAGE_DOCUMENTS], new MemoryDocumentRepository(), new MemoryDocumentFileStore());

        $this->expectException(InvalidArgumentException::class);
        $archive->add('Anteckning', 'bara text', DocumentVisibility::Public);
    }

    public function test_schema_migration_stores_document_visibility(): void
    {
        $migration = new DocumentSchemaMigration('wp_', '');
        $sql = $migration->statements();

        self::assertSame(12, $migration->version());
        self::assertStringContainsString('CREATE TABLE wp_assoc_document', $sql);
        self::assertStringContainsString("visibility varchar(32) NOT NULL default 'board'", $sql);
        self::assertStringNotContainsString('wp_users', $sql);
    }

    /**
     * @param list<string> $capabilities
     */
    private function archive(array $capabilities, DocumentRepository $documents, DocumentFileStore $files): DocumentArchive
    {
        return new DocumentArchive(
            $documents,
            $files,
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

final class MemoryDocumentRepository implements DocumentRepository
{
    /** @var array<int, AssociationDocument> */
    private array $documents = [];

    private int $nextId = 1;

    public function add(AssociationDocument $document): AssociationDocument
    {
        $saved = $document->withId($this->nextId);
        $this->documents[$this->nextId] = $saved;
        $this->nextId++;

        return $saved;
    }

    public function save(AssociationDocument $document): void
    {
        $id = $document->id();

        if ($id === null || ! isset($this->documents[$id])) {
            throw new \RuntimeException('Document was not found.');
        }

        $this->documents[$id] = $document;
    }

    public function find(int $id): ?AssociationDocument
    {
        return $this->documents[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->documents);
    }
}

final class MemoryDocumentFileStore implements DocumentFileStore
{
    /** @var array<string, string> */
    private array $files = [];

    public int $writes = 0;

    public function put(string $name, string $bytes): void
    {
        $this->files[$name] = $bytes;
        $this->writes++;
    }

    public function read(string $name): string
    {
        if (! isset($this->files[$name])) {
            throw new \RuntimeException('The document was not found.');
        }

        return $this->files[$name];
    }
}
