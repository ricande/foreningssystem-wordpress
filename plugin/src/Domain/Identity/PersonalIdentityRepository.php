<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Identity;

interface PersonalIdentityRepository
{
    public function findForPerson(int $personId): ?PersonalIdentityRecord;

    public function save(PersonalIdentityRecord $record): PersonalIdentityRecord;

    public function remove(int $personId): void;
}
