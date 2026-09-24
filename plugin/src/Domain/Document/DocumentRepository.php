<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Document;

interface DocumentRepository
{
    public function add(AssociationDocument $document): AssociationDocument;

    public function save(AssociationDocument $document): void;

    public function find(int $id): ?AssociationDocument;

    /**
     * @return list<AssociationDocument>
     */
    public function all(): array;
}
