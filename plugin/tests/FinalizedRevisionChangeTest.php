<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Domain\Meeting\FinalizedRevisionChange;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\PublicationVisibility;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Infrastructure\Persistence\MinutesRevisionNumberSchemaMigration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FinalizedRevisionChangeTest extends TestCase
{
    public function test_a_finalized_revision_can_change_publication_and_supersession_only(): void
    {
        $stored = $this->revision(RevisionState::Finalized, 'Beslutet står fast.', null, PublicationVisibility::Board);
        FinalizedRevisionChange::assertAllowed($stored, $stored->withVisibility(PublicationVisibility::Public));
        FinalizedRevisionChange::assertAllowed($stored, $stored->markedSuperseded(9));

        $this->expectException(InvalidArgumentException::class);
        FinalizedRevisionChange::assertAllowed($stored, $stored->withBody('En annan text.'));
    }

    public function test_a_draft_can_still_change_its_text(): void
    {
        $stored = $this->revision(RevisionState::Draft, 'Utkast.', null, PublicationVisibility::Board);
        FinalizedRevisionChange::assertAllowed($stored, $stored->withBody('Utkastet är ändrat.'));
        self::assertSame('Utkastet är ändrat.', $stored->withBody('Utkastet är ändrat.')->body());
    }

    public function test_revision_numbers_are_unique_per_meeting(): void
    {
        $sql = (new MinutesRevisionNumberSchemaMigration('wp_', ''))->statements();

        self::assertSame(14, (new MinutesRevisionNumberSchemaMigration('wp_', ''))->version());
        self::assertStringContainsString('UNIQUE KEY meeting_revision (meeting_id,revision_number)', $sql);
        self::assertStringContainsString('pdf_storage_name', $sql);
        self::assertStringContainsString('visibility varchar(32)', $sql);
    }

    private function revision(RevisionState $state, string $body, ?int $supersededBy, PublicationVisibility $visibility): MinutesRevision
    {
        return new MinutesRevision(4, 2, 8, 1, $state, $body, '{"decision":"x"}', false, null, $supersededBy, $visibility);
    }
}
