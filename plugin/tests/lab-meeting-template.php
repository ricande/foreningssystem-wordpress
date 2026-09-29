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
    $meetingIds = $wpdb->get_col("SELECT id FROM {$meetings} WHERE title IN ('LAB-TEMPLATE', 'LAB-TEMPLATE-A', 'LAB-TEMPLATE-B')");

    if (is_array($meetingIds)) {
        foreach ($meetingIds as $meetingId) {
            $wpdb->delete($agenda, ['meeting_id' => (int) $meetingId], ['%d']);
            $wpdb->delete($meetings, ['id' => (int) $meetingId], ['%d']);
        }
    }

    $templateIds = $wpdb->get_col("SELECT id FROM {$templates} WHERE name IN ('LAB-TEMPLATE-MALL', 'LAB-TEMPLATE-EGEN', 'LAB-TEMPLATE-EGEN-SPARAD')");

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
$_GET['assoc_template_step'] = 'choose';
ob_start();
MeetingsPage::render();
$choose = (string) ob_get_clean();
$_GET['assoc_template_step'] = 'items';
$_GET['starter'] = 'annual';
ob_start();
MeetingsPage::render();
$points = (string) ob_get_clean();
unset($_GET['assoc_template_step'], $_GET['starter']);

$emptyId = WordpressMeetings::templates()->createFromStarter('empty', 'LAB-TEMPLATE-EGEN', $typeId, []);
WordpressMeetings::templates()->addHeading($emptyId, 'Egen punkt');
WordpressMeetings::templates()->rename($emptyId, 'LAB-TEMPLATE-EGEN-SPARAD');
$opened = WordpressMeetings::templates()->headings($emptyId);
$openedName = '';

foreach (WordpressMeetings::templates()->all() as $template) {
    if ((int) $template->id() === $emptyId) {
        $openedName = $template->name();
    }
}

$meetingA = WordpressMeetings::service()->schedule(
    $typeId,
    'LAB-TEMPLATE-A',
    MeetingMoment::fromLocal('2027-04-02 18:00'),
    'Lokalen'
);
$meetingB = WordpressMeetings::service()->schedule(
    $typeId,
    'LAB-TEMPLATE-B',
    MeetingMoment::fromLocal('2028-04-02 18:00'),
    'Lokalen'
);
WordpressMeetings::templates()->copyOnto($meetingA, $emptyId);
WordpressMeetings::templates()->copyOnto($meetingB, $emptyId);
WordpressMeetings::templates()->renameHeading($emptyId, (int) $opened[0]->id(), 'Ändrad mall');
$savedTitle = WordpressMeetings::templates()->headings($emptyId)[0]->title();
$titlesA = $wpdb->get_col($wpdb->prepare("SELECT title FROM {$agenda} WHERE meeting_id = %d ORDER BY position ASC", $meetingA));
$titlesB = $wpdb->get_col($wpdb->prepare("SELECT title FROM {$agenda} WHERE meeting_id = %d ORDER BY position ASC", $meetingB));

if (
    $denied !== true
    || $mismatched !== true
    || $titles !== ['LAB öppnande', 'LAB nästa']
    || $openedName !== 'LAB-TEMPLATE-EGEN-SPARAD'
    || count($opened) !== 1
    || $opened[0]->title() !== 'Egen punkt'
    || $savedTitle !== 'Ändrad mall'
    || $titlesA !== ['Egen punkt']
    || $titlesB !== ['Egen punkt']
    || ! str_contains($html, 'Skapa en mötesmall från en startmall')
    || ! str_contains($html, 'Skapa ny mall')
    || ! str_contains($html, 'LAB-TEMPLATE-MALL')
    || ! str_contains($html, 'Öppna mall')
    || ! str_contains($choose, 'Tom mall')
    || ! str_contains($choose, 'Föreningsmöte')
    || ! str_contains($choose, 'Årsmöte')
    || ! str_contains($points, 'Fastställande av röstlängd')
    || ! str_contains($points, 'Val av mötesordförande')
    || ! str_contains($points, 'Mötets avslutande')
    || ! str_contains($points, 'name="heading[]"')
    || str_contains($html . $choose . $points, 'Val av styrelse')
) {
    $fail('The meeting template rewrote an existing agenda or arrived with a hardcoded annual agenda.');
}

$cleanup();
$left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$templates} WHERE name IN ('LAB-TEMPLATE-MALL', 'LAB-TEMPLATE-EGEN', 'LAB-TEMPLATE-EGEN-SPARAD')");

if ($left !== 0) {
    \WP_CLI::error('The lab meeting template was not removed.');
}

\WP_CLI::success('A meeting template is copied once and does not follow later edits.');
