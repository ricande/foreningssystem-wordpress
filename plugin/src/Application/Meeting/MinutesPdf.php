<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\MinutesRepository;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\RevisionState;

final class MinutesPdf
{
    public function __construct(
        private readonly MinutesRepository $minutes,
        private readonly MinutesPdfStore $files,
        private readonly MinutesPdfDocument $document,
        private readonly Authorizer $authorizer,
        private readonly string $revisionLabel = 'Revision %d',
        private readonly string $pageLabel = 'Page %1$d / %2$d',
    ) {
    }

    public function readable(int $revisionId): MinutesRevision
    {
        $revision = $this->minutes->findRevision($revisionId);

        if (! $revision instanceof MinutesRevision) {
            throw new \RuntimeException('Minutes revision was not found.');
        }

        if ($revision->state() === RevisionState::Finalized) {
            $this->require(Capabilities::VIEW_INTERNAL_MEETINGS);
        } else {
            $this->require(Capabilities::RECORD_MEETING);
        }

        return $revision;
    }

    public function bytes(int $revisionId): string
    {
        $revision = $this->readable($revisionId);
        $hash = hash('sha256', $revision->body() . "\n" . $revision->payload());
        $stored = $this->files->stored($revisionId);

        if ($stored !== null && hash_equals($stored['hash'], $hash)) {
            try {
                return $this->files->read($revisionId);
            } catch (\RuntimeException) {
                // The recorded file is missing, so render it again.
            }
        }

        $bytes = $this->document->render(
            $revision->body(),
            sprintf($this->revisionLabel, $revision->number()),
            $this->pageLabel
        );
        $this->files->put($revisionId, $hash, $bytes);

        return $bytes;
    }

    private function require(string $capability): void
    {
        if (! $this->authorizer->allows($capability)) {
            throw new NotAllowed($capability);
        }
    }
}
