<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Account;

interface WordPressAccountGateway
{
    public function findUserIdByEmail(string $email): ?int;

    public function findUserIdByLogin(string $login): ?int;

    public function userExists(int $userId): bool;

    public function displayName(int $userId): string;

    public function email(int $userId): string;

    /**
     * @return list<string>
     */
    public function roles(int $userId): array;

    public function createSubscriber(string $login, string $email, string $displayName): int;

    public function deleteCreatedUser(int $userId): void;

    public function notifyNewUser(int $userId): void;
}
