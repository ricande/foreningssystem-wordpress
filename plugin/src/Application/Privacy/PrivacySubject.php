<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Privacy;

use Foreningssystem\Domain\Person\Person;
use Foreningssystem\Domain\Person\PersonRepository;

/**
 * The single person a privacy request is about.
 *
 * A verified WordPress account link identifies one person. An email address does not: a
 * family often shares one address, so an address held by several people names nobody in
 * particular. Such a request resolves to no person at all: nothing is exported and nobody
 * is anonymized, which keeps one person's data away from another person's request.
 */
final class PrivacySubject
{
    public static function resolve(PersonRepository $people, string $email, ?int $linkedUserId): ?Person
    {
        $needle = trim($email);
        $userId = $linkedUserId !== null && $linkedUserId >= 1 ? $linkedUserId : null;
        $linked = [];
        $sharingEmail = [];

        foreach ($people->all() as $person) {
            $id = $person->id();

            if ($id === null) {
                continue;
            }

            if ($userId !== null && $person->wordpressUserId() === $userId) {
                $linked[$id] = $person;
            }

            if ($needle !== '' && $person->email() !== '' && strcasecmp($person->email(), $needle) === 0) {
                $sharingEmail[$id] = $person;
            }
        }

        if ($linked !== []) {
            return count($linked) === 1 ? array_values($linked)[0] : null;
        }

        return count($sharingEmail) === 1 ? array_values($sharingEmail)[0] : null;
    }
}
