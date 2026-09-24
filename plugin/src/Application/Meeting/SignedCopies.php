<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\AuditLog;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MinutesRepository;
use Foreningssystem\Domain\Meeting\MinutesRevision;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Domain\Meeting\SignedCopy;
use Foreningssystem\Domain\Meeting\SignedCopyRepository;
use Foreningssystem\Domain\Meeting\SignedCopyType;
use InvalidArgumentException;

final class SignedCopies
{
    public function __construct(
        private readonly MinutesRepository $minutes,
        private readonly SignedCopyRepository $copies,
        private readonly SignedFileStore $files,
        private readonly AuditLog $audit,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
    ) {
    }

    public function attach(int $revisionId, string $bytes, int $actorUserId): string
    {
        $this->requireUpload();

        if ($actorUserId < 1) {
            throw new InvalidArgumentException('The signed copy needs an officer account.');
        }

        $revision = $this->requireFinalized($revisionId);
        $mediaType = SignedCopyType::fromBytes($bytes);
        $name = 'signed-' . $revision->id() . '-' . hash('sha256', $bytes) . '.' . SignedCopyType::extension($mediaType);
        $this->files->put($name, $bytes);

        return $this->transaction->run(function () use ($revision, $mediaType, $name, $actorUserId): string {
            $current = $this->copies->currentForRevision((int) $revision->id());
            $saved = $this->copies->add((int) $revision->id(), $mediaType, $name);
            $savedId = $saved->id();

            if ($savedId === null) {
                throw new \RuntimeException('The signed copy was not saved.');
            }

            if ($current instanceof SignedCopy && $current->id() !== null) {
                $this->copies->markReplaced((int) $current->id(), $savedId);
                $this->audit->record('minutes_revision', (int) $revision->id(), 'replace_signed_copy', $actorUserId);

                return 'replaced';
            }

            $this->audit->record('minutes_revision', (int) $revision->id(), 'attach_signed_copy', $actorUserId);

            return 'attached';
        });
    }

    public function current(int $revisionId): ?SignedCopy
    {
        $this->requireView();
        $this->requireFinalized($revisionId);

        return $this->copies->currentForRevision($revisionId);
    }

    public function read(int $revisionId): string
    {
        $copy = $this->current($revisionId);

        if (! $copy instanceof SignedCopy) {
            throw new \RuntimeException('Signed copy was not found.');
        }

        return $this->files->read($copy->storageName());
    }

    private function requireFinalized(int $revisionId): MinutesRevision
    {
        $revision = $this->minutes->findRevision($revisionId);

        if (! $revision instanceof MinutesRevision || $revision->id() === null) {
            throw new \RuntimeException('Minutes revision was not found.');
        }

        if ($revision->state() !== RevisionState::Finalized) {
            throw new MeetingRuleException('A signed copy belongs to a finalized revision.');
        }

        return $revision;
    }

    private function requireUpload(): void
    {
        if (! $this->authorizer->allows(Capabilities::MANAGE_DOCUMENTS) || ! $this->authorizer->allows(Capabilities::FINALIZE_MINUTES)) {
            throw new NotAllowed(Capabilities::FINALIZE_MINUTES);
        }
    }

    private function requireView(): void
    {
        if ($this->authorizer->allows(Capabilities::VIEW_INTERNAL_MEETINGS) || $this->authorizer->allows(Capabilities::VIEW_BOARD_DOCUMENTS)) {
            return;
        }

        throw new NotAllowed(Capabilities::VIEW_BOARD_DOCUMENTS);
    }
}
