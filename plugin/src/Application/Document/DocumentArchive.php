<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Document;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Document\AssociationDocument;
use Foreningssystem\Domain\Document\DocumentFileType;
use Foreningssystem\Domain\Document\DocumentRepository;
use Foreningssystem\Domain\Document\DocumentVisibility;

final class DocumentArchive
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentFileStore $files,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
        private readonly ActiveMember $members,
    ) {
    }

    public function add(string $title, string $bytes, DocumentVisibility $visibility): int
    {
        $this->requireManage();
        $mediaType = DocumentFileType::fromBytes($bytes);
        $name = 'document-' . hash('sha256', $bytes) . '.' . DocumentFileType::extension($mediaType);
        $this->files->put($name, $bytes);

        $saved = $this->transaction->run(function () use ($title, $visibility, $mediaType, $name): AssociationDocument {
            return $this->documents->add(new AssociationDocument(null, $title, $visibility, $mediaType, $name));
        });
        $id = $saved->id();

        if ($id === null) {
            throw new \RuntimeException('The document was not saved.');
        }

        return $id;
    }

    public function setVisibility(int $documentId, DocumentVisibility $visibility): void
    {
        $this->requireManage();
        $document = $this->requireDocument($documentId);

        $this->transaction->run(function () use ($document, $visibility): void {
            $this->documents->save($document->withVisibility($visibility));
        });
    }

    /**
     * @return list<ListedDocument>
     */
    public function publicList(): array
    {
        $rows = [];

        foreach ($this->documents->all() as $document) {
            if ($document->visibility() === DocumentVisibility::Public && $document->id() !== null) {
                $rows[] = $this->list($document);
            }
        }

        return $rows;
    }

    /**
     * @return list<ListedDocument>|null
     */
    public function memberList(): ?array
    {
        if (! $this->members->coversCurrentUser()) {
            return null;
        }

        $rows = [];

        foreach ($this->documents->all() as $document) {
            if ($document->visibility() === DocumentVisibility::Member && $document->id() !== null) {
                $rows[] = $this->list($document);
            }
        }

        return $rows;
    }

    /**
     * @return list<ListedDocument>
     */
    public function officerList(): array
    {
        $this->requireOfficer();
        $rows = [];

        foreach ($this->documents->all() as $document) {
            if ($document->id() !== null) {
                $rows[] = $this->list($document);
            }
        }

        return $rows;
    }

    public function open(int $documentId): ListedDocument
    {
        return $this->list($this->requireReadable($documentId));
    }

    public function read(int $documentId): string
    {
        return $this->files->read($this->requireReadable($documentId)->storageName());
    }

    private function list(AssociationDocument $document): ListedDocument
    {
        $id = $document->id();

        if ($id === null) {
            throw new \RuntimeException('The document was not saved.');
        }

        return new ListedDocument($id, $document->title(), $document->visibility(), $document->mediaType());
    }

    private function requireReadable(int $documentId): AssociationDocument
    {
        $document = $this->requireDocument($documentId);

        if ($document->visibility() === DocumentVisibility::Public) {
            return $document;
        }

        if ($document->visibility() === DocumentVisibility::Member) {
            if ($this->members->coversCurrentUser()) {
                return $document;
            }

            throw new NotAllowed('active_membership');
        }

        if (! $this->authorizer->allows(Capabilities::VIEW_BOARD_DOCUMENTS) && ! $this->authorizer->allows(Capabilities::MANAGE_DOCUMENTS)) {
            throw new NotAllowed(Capabilities::VIEW_BOARD_DOCUMENTS);
        }

        return $document;
    }

    private function requireDocument(int $documentId): AssociationDocument
    {
        $document = $this->documents->find($documentId);

        if (! $document instanceof AssociationDocument || $document->id() === null) {
            throw new \RuntimeException('Document was not found.');
        }

        return $document;
    }

    private function requireManage(): void
    {
        if (! $this->authorizer->allows(Capabilities::MANAGE_DOCUMENTS)) {
            throw new NotAllowed(Capabilities::MANAGE_DOCUMENTS);
        }
    }

    private function requireOfficer(): void
    {
        if (! $this->authorizer->allows(Capabilities::VIEW_BOARD_DOCUMENTS) && ! $this->authorizer->allows(Capabilities::MANAGE_DOCUMENTS)) {
            throw new NotAllowed(Capabilities::VIEW_BOARD_DOCUMENTS);
        }
    }
}
