<?php

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Domain\Access\RoleCapabilitySetting;
use Foreningssystem\Infrastructure\WordPress\WordpressAccess;

/**
 * Runs inside the lab through WP-CLI. Creates synthetic users only.
 */

WordpressAccess::sync();

$secretary = lab_access_user('lab-secretary', RoleBundles::SECRETARY);
$chair = lab_access_user('lab-chair', RoleBundles::CHAIR);
$secretary = lab_access_fresh($secretary->ID);
$chair = lab_access_fresh($chair->ID);

lab_access_assert(! user_can($secretary, Capabilities::FINALIZE_MINUTES), 'Secretary can finalize by default.');
lab_access_assert(user_can($secretary, Capabilities::RECORD_MEETING), 'Secretary cannot record meetings.');
lab_access_assert(user_can($chair, Capabilities::FINALIZE_MINUTES), 'Chair cannot finalize by default.');
lab_access_assert(! user_can($secretary, 'install_plugins'), 'Secretary can install plugins.');

$setting = RoleCapabilitySetting::defaults()->grant(RoleBundles::SECRETARY, Capabilities::FINALIZE_MINUTES);
WordpressAccess::save($setting);
WordpressAccess::sync();
$secretary = lab_access_fresh($secretary->ID);
lab_access_assert(user_can($secretary, Capabilities::FINALIZE_MINUTES), 'The setting did not grant finalize_minutes.');

WordpressAccess::save(RoleCapabilitySetting::defaults());
WordpressAccess::sync();
$secretary = lab_access_fresh($secretary->ID);
lab_access_assert(! user_can($secretary, Capabilities::FINALIZE_MINUTES), 'The default setting was not restored.');

\WP_CLI::success('Association roles match the setting.');

function lab_access_user(string $login, string $role): WP_User
{
    $user = get_user_by('login', $login);

    if (! $user instanceof WP_User) {
        $created = wp_insert_user([
            'user_login' => $login,
            'user_pass' => wp_generate_password(24),
            'user_email' => $login . '@example.test',
            'role' => $role,
        ]);

        if (is_wp_error($created)) {
            \WP_CLI::error($created->get_error_message());
        }

        $user = get_user_by('id', $created);
    }

    if (! $user instanceof WP_User) {
        \WP_CLI::error('Could not create ' . $login);
    }

    $user->set_role($role);

    return lab_access_fresh($user->ID);
}

function lab_access_fresh(int $userId): WP_User
{
    clean_user_cache($userId);
    $user = get_user_by('id', $userId);

    if (! $user instanceof WP_User) {
        \WP_CLI::error('Missing lab user ' . (string) $userId);
    }

    return $user;
}

function lab_access_assert(bool $condition, string $message): void
{
    if (! $condition) {
        \WP_CLI::error($message);
    }
}
