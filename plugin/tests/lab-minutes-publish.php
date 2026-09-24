<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Domain\Access\RoleCapabilitySetting;
use Foreningssystem\Infrastructure\WordPress\MinutesPublishPage;
use Foreningssystem\Infrastructure\WordPress\WordpressAccess;
use Foreningssystem\Infrastructure\WordPress\WordpressMinutesPublish;

global $wpdb;

$audit = $wpdb->prefix . 'assoc_audit_event';

$cleanup = static function () use ($wpdb, $audit): void {
    WordpressAccess::save(RoleCapabilitySetting::defaults());
    WordpressAccess::sync();
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$audit} WHERE object_type = %s AND action IN (%s, %s)",
        'association_role',
        'grant_publish_minutes',
        'revoke_publish_minutes'
    ));

    foreach (['lab-secretary', 'lab-chair', 'lab-board-member'] as $login) {
        $user = get_user_by('login', $login);

        if ($user instanceof WP_User) {
            clean_user_cache((int) $user->ID);
        }
    }
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$fresh = static function (string $login): WP_User {
    $user = get_user_by('login', $login);

    if (! $user instanceof WP_User) {
        \WP_CLI::error('Missing lab user ' . $login);
    }

    clean_user_cache((int) $user->ID);
    $freshUser = get_user_by('id', (int) $user->ID);

    if (! $freshUser instanceof WP_User) {
        \WP_CLI::error('Missing lab user ' . $login);
    }

    return $freshUser;
};

$cleanup();
$secretary = $fresh('lab-secretary');
$denied = false;
wp_set_current_user((int) $secretary->ID);

try {
    WordpressMinutesPublish::update([RoleBundles::CHAIR, RoleBundles::SECRETARY]);
} catch (NotAllowed $error) {
    $denied = $error->getMessage() === Capabilities::MANAGE_ASSOCIATION;
}

$secretary = $fresh('lab-secretary');
wp_set_current_user(1);
clean_user_cache(1);
ob_start();
MinutesPublishPage::render();
$html = (string) ob_get_clean();
WordpressMinutesPublish::update([RoleBundles::CHAIR, RoleBundles::SECRETARY]);
$secretary = $fresh('lab-secretary');
$chair = $fresh('lab-chair');
$boardMember = $fresh('lab-board-member');
$events = $wpdb->get_results($wpdb->prepare(
    "SELECT object_id, action, actor_user_id FROM {$audit} WHERE object_type = %s AND action = %s",
    'association_role',
    'grant_publish_minutes'
), ARRAY_A);

if (
    $denied !== true
    || user_can($secretary, Capabilities::PUBLISH_MINUTES) !== true
    || user_can($secretary, Capabilities::FINALIZE_MINUTES) !== false
    || user_can($secretary, Capabilities::RECORD_MEETING) !== true
    || user_can($secretary, 'install_plugins') !== false
    || user_can($chair, Capabilities::PUBLISH_MINUTES) !== true
    || user_can($chair, Capabilities::FINALIZE_MINUTES) !== true
    || user_can($boardMember, Capabilities::PUBLISH_MINUTES) !== false
    || ! str_contains($html, 'Ordföranden får publicera ett låst protokoll från början')
    || ! str_contains($html, 'value="assoc_chair"')
    || ! str_contains($html, 'checked')
    || str_contains($html, 'value="assoc_secretary" checked')
    || ! is_array($events)
    || count($events) !== 1
    || (int) $events[0]['object_id'] !== RoleBundles::auditId(RoleBundles::SECRETARY)
    || (string) $events[0]['action'] !== 'grant_publish_minutes'
    || (int) $events[0]['actor_user_id'] !== 1
    || str_contains((string) $events[0]['action'], '@')
) {
    $fail('The publication setting changed who may lock minutes or left the secretary unchanged.');
}

$cleanup();
$secretary = $fresh('lab-secretary');
$chair = $fresh('lab-chair');

if (user_can($secretary, Capabilities::PUBLISH_MINUTES) || ! user_can($chair, Capabilities::PUBLISH_MINUTES) || ! user_can($chair, Capabilities::FINALIZE_MINUTES)) {
    \WP_CLI::error('The default publication setting was not restored.');
}

\WP_CLI::success('The association can choose which role may publish a locked revision.');
