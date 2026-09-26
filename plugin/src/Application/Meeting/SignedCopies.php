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
        private readonly SignedCopyLock $lock,
    ) {
    }

    public function attach(int $revisionId, string $bytes, int $actorUserId, ?int $meetingId = null): string
    {
        $this->requireUpload();

        if ($actorUserId < 1) {
            throw new InvalidArgumentException('The signed copy needs an officer account.');
        }

        $revision = $this->requireFinalized($revisionId);

        if ($meetingId !== null && $revision->meetingId() !== $meetingId) {
            throw new MeetingRuleException('The record does not belong to this meeting.');
        }
        $mediaType = SignedCopyType::fromBytes($bytes);
        $id = (int) $revision->id();
        $name = 'signed-' . $id . '-' . hash('sha256', $bytes) . '.' . SignedCopyType::extension($mediaType);
        $this->lock->acquire($id);

        try {
            $this->files->put($name, $bytes);

            return $this->transaction->run(function () use ($id, $mediaType, $name, $actorUserId): string {
                $saved = $this->copies->add($id, $mediaType, $name);
                $savedId = $saved->id();

                if ($savedId === null) {
                    throw new \RuntimeException('The signed copy was not saved.');
                }

                if ($this->copies->replaceCurrent($id, $savedId) > 0) {
                    $this->audit->record('minutes_revision', $id, 'replace_signed_copy', $actorUserId);

                    return 'replaced';
                }

                $this->audit->record('minutes_revision', $id, 'attach_signed_copy', $actorUserId);

                return 'attached';
            });
        } catch (\Throwable $error) {
            // The upload is undone, but the same bytes can belong to a copy that was
            // attached earlier, current or already replaced. That file is a record of its
            // own and stays.
            if (! $this->copies->hasStorageName($id, $name)) {
                $this->files->discard($name);
            }

            throw $error;
        } finally {
            $this->lock->release($id);
        }
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
