<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Infrastructure\WordPress\MeetingsPage;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;

global $wpdb;

$meetings = $wpdb->prefix . 'assoc_meeting';
$agenda = $wpdb->prefix . 'assoc_agenda_item';
$templates = $wpdb->prefix . 'assoc_meeting_template';
$items = $wpdb->prefix . 'assoc_meeting_template_item';

$cleanup = static function () use ($wpdb, $meetings, $agenda, $templates, $items): void {
    $meetingIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$meetings} WHERE title = %s", 'LAB-TEMPLATE'));

    if (is_array($meetingIds)) {
        foreach ($meetingIds as $meetingId) {
            $wpdb->delete($agenda, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($meetings, ['id' => (int) $meetingId], ['%d']);
        }
    }

    $templateIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$templates} WHERE name = %s", 'LAB-TEMPLATE-MALL'));

    if (is_array($templateIds)) {
        foreach ($templateIds as $templateId) {
            $wpdb->delete($items, ['template_id' => (int) $templateId], ['%d']);
            $wpdb->delete($templates, ['id' => (int) $templateId], ['%d']);
        }
    }
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();
$secretary = get_user_by('login', 'lab-secretary');
$boardMember = get_user_by('login', 'lab-board-member');

if (! $secretary instanceof WP_User || ! $boardMember instanceof WP_User) {
    \WP_CLI::error('Missing lab meeting users');
}

clean_user_cache((int) $boardMember->ID);
wp_set_current_user((int) $boardMember->ID);
$denied = false;

try {
    WordpressMeetings::templates()->create(1, 'LAB-TEMPLATE-MALL');
} catch (NotAllowed $error) {
    $denied = $error->getMessage() === Capabilities::MANAGE_MEETINGS;
}

clean_user_cache((int) $secretary->ID);
wp_set_current_user((int) $secretary->ID);
$typeId = null;
$otherTypeId = null;

foreach (WordpressMeetings::service()->types() as $type) {
    if ($type->slug() === 'board_meeting') {
        $typeId = $type->id();
    }

    if ($type->slug() === 'member_meeting') {
        $otherTypeId = $type->id();
    }
}

if ($typeId === null || $otherTypeId === null) {
    $fail('Meeting types were not seeded.');
}

$templateId = WordpressMeetings::templates()->create($typeId, 'LAB-TEMPLATE-MALL');
WordpressMeetings::templates()->addHeading($templateId, 'LAB öppnande');
WordpressMeetings::templates()->addHeading($templateId, 'LAB nästa');
$meetingId = WordpressMeetings::service()->schedule(
    $typeId,
    'LAB-TEMPLATE',
    MeetingMoment::fromLocal('2024-05-02 18:00'),
    'Lokalen'
);
WordpressMeetings::templates()->copyOnto($meetingId, $templateId);
WordpressMeetings::templates()->addHeading($templateId, 'LAB övrigt');
$titles = $wpdb->get_col($wpdb->prepare(
    "SELECT title FROM {$agenda} WHERE meeting_id = %d ORDER BY position ASC",
    $meetingId
));
$mismatched = false;

try {
    WordpressMeetings::templates()->assertForType($templateId, $otherTypeId);
} catch (MeetingRuleException) {
    $mismatched = true;
}

ob_start();
MeetingsPage::render();
$html = (string) ob_get_clean();

if (
    $denied !== true
    || $mismatched !== true
    || $titles !== ['LAB öppnande', 'LAB nästa']
    || ! str_contains($html, 'En mall är en lista med rubriker')
    || ! str_contains($html, 'LAB-TEMPLATE-MALL')
    || str_contains($html, 'Val av styrelse')
) {
    $fail('The meeting template rewrote an existing agenda or arrived with a hardcoded annual agenda.');
}

$cleanup();
$left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$templates} WHERE name = %s", 'LAB-TEMPLATE-MALL'));

if ($left !== 0) {
    \WP_CLI::error('The lab meeting template was not removed.');
}

\WP_CLI::success('A meeting template is copied once and does not follow later edits.');
