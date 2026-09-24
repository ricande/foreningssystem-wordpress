<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\Meeting\MinutesPdf;
use Foreningssystem\Application\Meeting\MinutesPdfDocument;
use Foreningssystem\Application\Meeting\MinutesPdfLayout;
use Foreningssystem\Application\Meeting\MinutesPdfStore;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Infrastructure\Persistence\MinutesPdfSchemaMigration;
use PHPUnit\Framework\TestCase;

final class MinutesPdfTest extends TestCase
{
    public function test_layout_keeps_a_heading_with_the_following_line(): void
    {
        $pages = (new MinutesPdfLayout(3))->pages("Rad ett\nRad två\nAvslutning\nJusterare: Ada");

        self::assertSame(['Rad ett', 'Rad två'], $pages[0]);
        self::assertSame(['Avslutning', 'Justerare: Ada'], $pages[1]);
    }

    public function test_pdf_contains_swedish_text_and_more_than_one_page(): void
    {
        $body = "Föreningen köper modell X.\n";

        for ($line = 1; $line <= 30; $line++) {
            $body .= "Mötesrad {$line} handlar om lokalen.\n";
        }

        $body .= "Avslutning\nJusterare: Ada Ärlig";
        $pdf = (new MinutesPdfDocument(new MinutesPdfLayout(10)))->render($body, 1);
        $swedish = iconv('UTF-8', 'Windows-1252', 'Föreningen köper modell X.');
        $adjuster = iconv('UTF-8', 'Windows-1252', 'Justerare: Ada Ärlig');

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringEndsWith("%%EOF", $pdf);
        self::assertIsString($swedish);
        self::assertIsString($adjuster);
        self::assertStringContainsString($swedish, $pdf);
        self::assertStringContainsString($adjuster, $pdf);
        self::assertGreaterThanOrEqual(3, substr_count($pdf, '/Type /Page /Parent'));
    }

    public function test_a_locked_pdf_follows_the_stored_text_and_is_reused(): void
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
        $files = new MemoryMinutesPdfStore();
        $pdf = new MinutesPdf($minutes, $files, new MinutesPdfDocument(new MinutesPdfLayout(10)), $this->authorizer([
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ]));
        $revisionId = (int) $revision->id();
        $first = $pdf->bytes($revisionId);
        $second = $pdf->bytes($revisionId);

        self::assertSame($first, $second);
        self::assertSame(1, $files->writes);
        self::assertStringContainsString((string) iconv('UTF-8', 'Windows-1252', 'Föreningen köper modell X.'), $first);
        self::assertStringNotContainsString('modell Y', $first);

        $minutes->saveRevision($revision->withBody('Föreningen köper modell Y.'));
        $edited = $pdf->bytes($revisionId);

        self::assertSame(2, $files->writes);
        self::assertStringContainsString((string) iconv('UTF-8', 'Windows-1252', 'modell Y'), $edited);
        self::assertNotSame($first, $edited);
    }

    public function test_a_board_reader_cannot_export_a_draft(): void
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
        $pdf = new MinutesPdf($minutes, new MemoryMinutesPdfStore(), new MinutesPdfDocument(), $this->authorizer([
            Capabilities::VIEW_INTERNAL_MEETINGS,
        ]));

        $this->expectException(NotAllowed::class);
        $pdf->bytes((int) $revision->id());
    }

    public function test_schema_migration_stores_the_pdf_source_hash(): void
    {
        $migration = new MinutesPdfSchemaMigration('wp_', '');
        $sql = $migration->statements();

        self::assertSame(9, $migration->version());
        self::assertStringContainsString('pdf_storage_name', $sql);
        self::assertStringContainsString('pdf_source_hash', $sql);
        self::assertStringNotContainsString('wp_users', $sql);
    }

    /**
     * @param list<string> $capabilities
     */
    private function authorizer(array $capabilities): Authorizer
    {
        return new class ($capabilities) implements Authorizer {
            /** @param list<string> $capabilities */
            public function __construct(private array $capabilities)
            {
            }

            public function allows(string $capability): bool
            {
                return in_array($capability, $this->capabilities, true);
            }
        };
    }
}

final class MemoryMinutesPdfStore implements MinutesPdfStore
{
    public int $writes = 0;

    /** @var array<int, array{hash: string, name: string, bytes: string}> */
    private array $files = [];

    public function stored(int $revisionId): ?array
    {
        if (! isset($this->files[$revisionId])) {
            return null;
        }

        return [
            'hash' => $this->files[$revisionId]['hash'],
            'name' => $this->files[$revisionId]['name'],
        ];
    }

    public function put(int $revisionId, string $hash, string $bytes): void
    {
        $this->writes++;
        $this->files[$revisionId] = [
            'hash' => $hash,
            'name' => 'revision-' . $revisionId . '-' . $hash . '.pdf',
            'bytes' => $bytes,
        ];
    }

    public function read(int $revisionId): string
    {
        if (! isset($this->files[$revisionId])) {
            throw new \RuntimeException('The PDF file was not found.');
        }

        return $this->files[$revisionId]['bytes'];
    }
}
