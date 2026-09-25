<?php

declare(strict_types=1);

namespace Foreningssystem\Domain\Person;

interface PersonRepository
{
    public function add(Person $person): Person;

    public function save(Person $person): void;

    public function find(int $id): ?Person;

    public function findByWordpressUserId(int $userId): ?Person;

    /**
     * @return list<Person>
     */
    public function all(): array;
}
