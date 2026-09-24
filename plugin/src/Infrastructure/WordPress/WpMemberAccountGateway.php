<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Account\WordPressAccountGateway;
use RuntimeException;
use WP_User;

final class WpMemberAccountGateway implements WordPressAccountGateway
{
    public function findUserIdByEmail(string $email): ?int
    {
        $found = email_exists($email);

        return is_numeric($found) && (int) $found > 0 ? (int) $found : null;
    }

    public function findUserIdByLogin(string $login): ?int
    {
        $found = username_exists($login);

        return is_numeric($found) && (int) $found > 0 ? (int) $found : null;
    }

    public function userExists(int $userId): bool
    {
        return $this->user($userId) instanceof WP_User;
    }

    public function displayName(int $userId): string
    {
        $user = $this->user($userId);

        return $user instanceof WP_User ? (string) $user->display_name : '';
    }

    public function email(int $userId): string
    {
        $user = $this->user($userId);

        return $user instanceof WP_User ? (string) $user->user_email : '';
    }

    public function roles(int $userId): array
    {
        $user = $this->user($userId);

        if (! $user instanceof WP_User) {
            return [];
        }

        $roles = [];

        foreach ($user->roles as $role) {
            if (is_string($role) && $role !== '') {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    public function createSubscriber(string $login, string $email, string $displayName): int
    {
        if ($this->findUserIdByLogin($login) !== null) {
            throw new RuntimeException('The login name is already used.');
        }

        $password = wp_generate_password(32, true, true);
        $created = wp_insert_user([
            'user_login' => $login,
            'user_pass' => $password,
            'user_email' => $email,
            'display_name' => $displayName,
            'nickname' => $displayName,
            'role' => 'subscriber',
        ]);
        unset($password);

        if ($created instanceof \WP_Error || ! is_int($created) || $created < 1) {
            throw new RuntimeException('The WordPress account could not be created.');
        }

        return $created;
    }

    public function deleteCreatedUser(int $userId): void
    {
        if ($userId < 1) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        if (get_userdata($userId) instanceof WP_User) {
            wp_delete_user($userId);
        }
    }

    public function notifyNewUser(int $userId): void
    {
        if ($userId < 1) {
            return;
        }

        wp_new_user_notification($userId, null, 'user');
    }

    private function user(int $userId): ?WP_User
    {
        if ($userId < 1) {
            return null;
        }

        $user = get_userdata($userId);

        return $user instanceof WP_User ? $user : null;
    }
}
