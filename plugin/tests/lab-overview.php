<?php

require __DIR__ . '/lab-membership.php';

use Foreningssystem\Application\Association\WorkOverview;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\Plugin;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$memberships = $wpdb->prefix . 'assoc_membership';
$meetings = $wpdb->prefix . 'assoc_meeting';
$actions = $wpdb->prefix . 'assoc_action_item';

$cleanup = static function () use ($wpdb, $people, $memberships, $meetings, $actions): void {
    $meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title LIKE %s", 'LAB-OVERVIEW%'));

    if (is_array($meetingIds)) {
        foreach ($meetingIds as $meetingId) {
            $wpdb->delete($actions, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($meetings, ['id' => (int) $meetingId], ['%d']);
        }
    }

    $personId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people} WHERE email = %s", 'lab-overview@example.test'));

    if ($personId) {
        lab_delete_person_memberships((int) $personId);
        $wpdb->delete($people, ['id' => (int) $personId], ['%d']);
    }
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();
wp_set_current_user(1);
clean_user_cache(1);
WordpressPeople::service()->register('Ada', 'Översikt', 'lab-overview@example.test', 'LAB-OVERVIEW-1', 'ordinarie', AssociationDate::fromIso('2024-01-01'));
$secretary = get_user_by('login', 'lab-secretary');

if (! $secretary instanceof WP_User) {
    $fail('Missing lab secretary');
}

clean_user_cache((int) $secretary->ID);
wp_set_current_user((int) $secretary->ID);
$typeId = null;

foreach (WordpressMeetings::service()->types() as $type) {
    if ($type->slug() === 'board_meeting') {
        $typeId = $type->id();
    }
}

if ($typeId === null) {
    $fail('Board meeting type was not seeded.');
}

$heldId = WordpressMeetings::service()->schedule($typeId, 'LAB-OVERVIEW hållet', MeetingMoment::fromLocal('2024-05-02 18:00'), '');
WordpressMeetings::service()->markHeld($heldId);
WordpressMeetings::record()->addActionItem($heldId, null, 'LAB-OVERVIEW förfallen', null, AssociationDate::fromIso('2020-01-01'));
$doneId = WordpressMeetings::record()->addActionItem($heldId, null, 'LAB-OVERVIEW klar', null, AssociationDate::fromIso('2020-01-02'));
WordpressMeetings::record()->setActionStatus($doneId, ActionStatus::Done);
WordpressMeetings::service()->schedule($typeId, 'LAB-OVERVIEW nästa', MeetingMoment::fromLocal('2026-12-01 18:00'), '');
WordpressMeetings::service()->schedule($typeId, 'LAB-OVERVIEW gammalt', MeetingMoment::fromLocal('2020-02-01 18:00'), '');
ob_start();
Plugin::renderAdminPage();
$html = (string) ob_get_clean();
$all = WordpressMeetings::service()->listMeetings();
$overview = new WorkOverview();
$next = $overview->nextPlanned($all, MeetingMoment::fromLocal(wp_date('Y-m-d H:i:s')));
$last = $overview->lastHeld($all);

if (
    $next === null
    || $next->title() === 'LAB-OVERVIEW gammalt'
    || $last === null
    || ! str_contains($html, 'Nästa möte')
    || ! str_contains($html, $next->title())
    || ! str_contains($html, 'Senaste hållet möte')
    || ! str_contains($html, $last->title())
    || ! str_contains($html, 'LAB-OVERVIEW förfallen')
    || str_contains($html, 'LAB-OVERVIEW klar')
    || str_contains($html, 'Databasschema')
) {
    $fail('The overview hid the next meeting or showed a finished task as overdue.');
}

$cleanup();

\WP_CLI::success('The overview shows the next meeting, the last held meeting, and overdue tasks.');
