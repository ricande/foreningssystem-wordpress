<?php

use Foreningssystem\Application\Account\AccountOutcome;
use Foreningssystem\Application\Settings\BuiltinStructure;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Association\AssociationProfile;
use Foreningssystem\Domain\Document\DocumentVisibility;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\RevisionState;
use Foreningssystem\Infrastructure\WordPress\AssociationOverviewPage;
use Foreningssystem\Infrastructure\WordPress\AssociationSettingsPage;
use Foreningssystem\Infrastructure\WordPress\BoardPage;
use Foreningssystem\Infrastructure\WordPress\DecisionsPage;
use Foreningssystem\Infrastructure\WordPress\DocumentsPage;
use Foreningssystem\Infrastructure\WordPress\MemberAreaBlock;
use Foreningssystem\Infrastructure\WordPress\MembersPage;
use Foreningssystem\Infrastructure\WordPress\MeetingsPage;
use Foreningssystem\Infrastructure\WordPress\Plugin;
use Foreningssystem\Infrastructure\WordPress\TasksPage;
use Foreningssystem\Infrastructure\WordPress\WordpressAssociationProfile;
use Foreningssystem\Infrastructure\WordPress\WordpressBoard;
use Foreningssystem\Infrastructure\WordPress\WordpressDocuments;
use Foreningssystem\Infrastructure\WordPress\WordpressMeetings;
use Foreningssystem\Infrastructure\WordPress\WordpressMemberAccounts;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;
use Foreningssystem\Domain\Membership\AssociationDate;

/**
 * @param list<string> $needles
 */
function release_render(callable $callback, array $needles, string $label): string
{
    ob_start();
    call_user_func($callback);
    $html = (string) ob_get_clean();

    if ($html === '' || preg_match('/Fatal error|Uncaught /', $html) === 1) {
        \WP_CLI::error($label . ' did not render.');
    }

    foreach ($needles as $needle) {
        if (! str_contains($html, $needle)) {
            \WP_CLI::error($label . ' did not include the expected text.');
        }
    }

    return $html;
}

function release_admin(): void
{
    $admin = get_user_by('login', 'admin');

    if (! $admin instanceof WP_User) {
        \WP_CLI::error('Scratch administrator is missing.');
    }

    clean_user_cache($admin->ID);
    wp_set_current_user($admin->ID);
}

function release_locale(): void
{
    if (get_locale() !== 'sv_SE') {
        switch_to_locale('sv_SE');
    }
}

function release_schema(): void
{
    $schema = get_option('assoc_schema_version', null);

    if ((string) $schema !== '16') {
        \WP_CLI::error('assoc_schema_version is ' . var_export($schema, true) . '.');
    }

    if (Plugin::VERSION !== '0.1.0' || ! defined('FORENINGSPLUGIN_VERSION') || FORENINGSPLUGIN_VERSION !== '0.1.0') {
        \WP_CLI::error('Packaged plugin version is not 0.1.0.');
    }
}

function release_tables(): void
{
    global $wpdb;

    $names = [];
    $files = glob(WP_PLUGIN_DIR . '/foreningsplugin/src/Infrastructure/Persistence/*.php');

    if (! is_array($files)) {
        \WP_CLI::error('Installed migrations could not be read.');
    }

    foreach ($files as $file) {
        $source = file_get_contents($file);

        if (! is_string($source)) {
            continue;
        }

        if (preg_match_all("/prefix \. '(assoc_[a-z0-9_]+)'/", $source, $matches) !== false) {
            foreach ($matches[1] as $name) {
                $names[$name] = true;
            }
        }
    }

    if (count($names) < 20) {
        \WP_CLI::error('Table inventory could not be derived from the installed migrations.');
    }

    $present = $wpdb->get_col('SHOW TABLES');

    if (! is_array($present)) {
        \WP_CLI::error('SHOW TABLES failed.');
    }

    foreach (array_keys($names) as $name) {
        $table = $wpdb->prefix . $name;

        if (! in_array($table, $present, true)) {
            \WP_CLI::error('Missing table ' . $table . '.');
        }
    }

    \WP_CLI::log('tables ' . count($names));
}

/**
 * @param list<object> $rows
 * @param list<string> $expected
 */
function release_slugs(array $rows, array $expected, string $label): void
{
    $counts = [];

    foreach ($rows as $row) {
        $slug = $row->slug();
        $counts[$slug] = ($counts[$slug] ?? 0) + 1;
    }

    foreach ($expected as $slug) {
        if (($counts[$slug] ?? 0) !== 1) {
            \WP_CLI::error($label . ' slug ' . $slug . ' count is ' . (string) ($counts[$slug] ?? 0) . '.');
        }
    }

    if (count($rows) !== count($expected)) {
        \WP_CLI::error($label . ' count is ' . (string) count($rows) . ', expected ' . (string) count($expected) . '.');
    }
}

function release_seeds(): void
{
    $roles = WordpressBoard::service()->roles();
    $types = WordpressMeetings::service()->types();
    release_slugs($roles, BuiltinStructure::boardSlugs(), 'board role');
    release_slugs($types, BuiltinStructure::meetingSlugs(), 'meeting type');
    \WP_CLI::log('board roles ' . implode(',', BuiltinStructure::boardSlugs()));
    \WP_CLI::log('meeting types ' . implode(',', BuiltinStructure::meetingSlugs()));
}

function release_capabilities(): void
{
    $admin = get_user_by('login', 'admin');
    $minimum = [
        Capabilities::ACCESS_ASSOCIATION,
        Capabilities::MANAGE_ASSOCIATION,
        Capabilities::VIEW_MEMBERS,
        Capabilities::EDIT_MEMBERS,
        Capabilities::VIEW_INTERNAL_MEETINGS,
        Capabilities::RECORD_MEETING,
        Capabilities::MANAGE_BOARD,
        Capabilities::VIEW_BOARD_DOCUMENTS,
        Capabilities::MANAGE_DOCUMENTS,
    ];
    $all = Capabilities::all();

    foreach ($minimum as $capability) {
        if (! in_array($capability, $all, true)) {
            \WP_CLI::error('Capability list is missing ' . $capability . '.');
        }
    }

    foreach ($all as $capability) {
        if (! user_can($admin, $capability)) {
            \WP_CLI::error('Administrator is missing ' . $capability . '.');
        }
    }

    \WP_CLI::log('administrator capabilities ' . (string) count($all));
}

function release_installed_tree(): void
{
    $root = WP_PLUGIN_DIR . '/foreningsplugin';

    foreach (['foreningsplugin.php', 'autoload.php', 'src', 'assets', 'languages'] as $present) {
        if (! file_exists($root . '/' . $present)) {
            \WP_CLI::error('Installed plugin is missing ' . $present . '.');
        }
    }

    foreach (['tests', 'composer.json', 'docker-compose.yml', '.env', '.git', 'scripts'] as $absent) {
        if (file_exists($root . '/' . $absent)) {
            \WP_CLI::error('Installed plugin contains ' . $absent . '.');
        }
    }
}

function release_blocks(): void
{
    $expected = [
        'foreningsplugin/latest-minutes',
        'foreningsplugin/current-board',
        'foreningsplugin/latest-board-meeting',
        'foreningsplugin/documents',
        'foreningsplugin/member-documents',
        'foreningsplugin/member-area',
        'foreningsplugin/member-count',
    ];
    $registry = WP_Block_Type_Registry::get_instance();
    $declared = [];
    $files = glob(WP_PLUGIN_DIR . '/foreningsplugin/src/Infrastructure/WordPress/*Block.php');

    if (! is_array($files)) {
        \WP_CLI::error('Installed block files could not be read.');
    }

    foreach ($files as $file) {
        $source = file_get_contents($file);

        if (! is_string($source)) {
            continue;
        }

        if (preg_match_all("/register_block_type\\('(foreningsplugin\\/[a-z0-9-]+)'/", $source, $matches) !== false) {
            foreach ($matches[1] as $name) {
                $declared[$name] = true;
            }
        }
    }

    foreach ($expected as $name) {
        if (! isset($declared[$name]) || ! $registry->is_registered($name)) {
            \WP_CLI::error('Block ' . $name . ' is not registered.');
        }
    }

    \WP_CLI::log('blocks ' . implode(',', $expected));
}

function release_translations(): void
{
    if (__('Tasks', 'foreningsplugin') !== 'Uppgifter' || __('Decisions', 'foreningsplugin') !== 'Beslut') {
        \WP_CLI::error('Packaged Swedish translations did not load.');
    }

    \WP_CLI::log('translations Tasks=Uppgifter Decisions=Beslut');
}

function release_empty_pages(): void
{
    $_GET = [];
    $_POST = [];
    release_render([AssociationOverviewPage::class, 'render'], ['Kom igång med föreningen'], 'overview');
    release_render([MembersPage::class, 'render'], ['Inga medlemmar ännu'], 'members');
    release_render([BoardPage::class, 'render'], ['Inga aktuella styrelseuppdrag'], 'board');
    release_render([MeetingsPage::class, 'render'], ['Inga möten ännu'], 'meetings');
    release_render([DecisionsPage::class, 'render'], ['Inga beslut har antecknats ännu'], 'decisions');
    release_render([TasksPage::class, 'render'], ['Inga uppgifter har antecknats ännu'], 'tasks');
    release_render([DocumentsPage::class, 'render'], ['Dokument'], 'documents');
    release_render([AssociationSettingsPage::class, 'render'], ['Föreningsinställningar'], 'settings');
    \WP_CLI::log('empty admin pages rendered');
}

function release_flow(): void
{
    $today = AssociationDate::fromIso(wp_date('Y-m-d'));
    WordpressAssociationProfile::save(new AssociationProfile(
        'Scratch Test Association',
        '',
        '',
        '',
        '',
        AssociationProfile::LANGUAGE_SWEDISH,
        null,
        1,
        1
    ));

    if (WordpressAssociationProfile::load()->name() !== 'Scratch Test Association') {
        \WP_CLI::error('Association profile was not saved.');
    }

    $personId = WordpressPeople::service()->register(
        'Anna',
        'Scratch',
        'anna.scratch@example.test',
        'SCRATCH-1',
        'ordinary',
        AssociationDate::fromIso('2024-01-01'),
        AssociationDate::fromIso('1990-01-01'),
        $today
    );
    $detail = WordpressPeople::directory()->personDetail($personId, $today);

    if (
        ! is_array($detail)
        || $detail['first_name'] !== 'Anna'
        || $detail['last_name'] !== 'Scratch'
        || $detail['email'] !== 'anna.scratch@example.test'
        || $detail['active_member'] !== true
        || ($detail['memberships'][0]['number'] ?? '') !== 'SCRATCH-1'
    ) {
        \WP_CLI::error('Ordinary member was not created with an active membership number.');
    }

    if (WordpressPeople::service()->activeMemberCount($today) !== 1) {
        \WP_CLI::error('Active member coverage did not include Anna.');
    }

    release_render([MembersPage::class, 'render'], ['Anna Scratch'], 'members after create');

    $chairId = null;

    foreach (WordpressBoard::service()->roles() as $role) {
        if ($role->slug() === 'chair' && $role->id() !== null) {
            $chairId = $role->id();
        }
    }

    if ($chairId === null) {
        \WP_CLI::error('Chair role was not seeded.');
    }

    WordpressBoard::service()->place(
        $personId,
        $chairId,
        AssociationDate::fromIso('2024-01-01'),
        null,
        'anna.scratch@example.test',
        '',
        $today
    );
    $foundChair = false;

    foreach (WordpressBoard::service()->currentPublic($today) as $seat) {
        if ($seat->roleSlug() === 'chair' && $seat->personName() === 'Anna Scratch') {
            $foundChair = true;
        }
    }

    if (! $foundChair) {
        \WP_CLI::error('Current board did not resolve Anna as chair.');
    }

    release_render([BoardPage::class, 'render'], ['Anna Scratch'], 'board after assignment');

    $typeId = null;

    foreach (WordpressMeetings::service()->types() as $type) {
        if ($type->slug() === 'board_meeting' && $type->id() !== null) {
            $typeId = $type->id();
        }
    }

    if ($typeId === null) {
        \WP_CLI::error('Board meeting type was not seeded.');
    }

    $meetings = WordpressMeetings::service();
    $record = WordpressMeetings::record();
    $meetingId = $meetings->schedule($typeId, 'Scratch board meeting', MeetingMoment::fromLocal('2026-09-01 18:00'), 'Scratch room');
    $meetings->start($meetingId);
    $itemId = WordpressMeetings::workspace()->addAgendaItem($meetingId, 'Scratch agenda', '');
    $record->addNote($meetingId, $itemId, 'Scratch noted the room', true);
    $record->addDecision($meetingId, $itemId, 'Scratch buys paper', $personId, null);
    $record->addActionItem($meetingId, $itemId, 'Scratch call the hall', $personId, AssociationDate::fromIso('2026-09-20'));
    $meetings->markHeld($meetingId);

    $held = false;

    foreach ($meetings->listMeetings() as $meeting) {
        if ($meeting->title() === 'Scratch board meeting' && $meeting->status() === MeetingStatus::Held) {
            $held = true;
        }
    }

    if (! $held) {
        \WP_CLI::error('Meeting was not marked held.');
    }

    $drafts = WordpressMeetings::minutes();
    $revisionId = $drafts->create($meetingId);
    $draft = $drafts->revision($revisionId);
    $payload = json_decode($draft->payload(), true);

    if (
        $draft->body() === ''
        || ! is_array($payload)
        || $payload === []
        || ! str_contains($draft->body(), 'Scratch buys paper')
        || ! str_contains($draft->body(), 'Scratch call the hall')
    ) {
        \WP_CLI::error('Minutes draft did not include the decision and task.');
    }

    $drafts->submit($revisionId);
    $drafts->finalize($revisionId);
    $final = $drafts->revision($revisionId);

    if ($final->state() !== RevisionState::Finalized) {
        \WP_CLI::error('Minutes were not finalized.');
    }

    $pdf = WordpressMeetings::pdf()->bytes($revisionId);

    if (! is_string($pdf) || strlen($pdf) < 8 || ! str_starts_with($pdf, '%PDF')) {
        \WP_CLI::error('Minutes PDF was not generated.');
    }

    \WP_CLI::log('pdf bytes ' . (string) strlen($pdf));

    $documentId = WordpressDocuments::archive()->add(
        'Scratch private note',
        "%PDF-1.1\nScratch private note\n%%EOF\n",
        DocumentVisibility::Board
    );

    if ($documentId < 1) {
        \WP_CLI::error('Private document was not stored.');
    }

    $uploads = wp_upload_dir();
    $private = $uploads['basedir'] . '/assoc-private';

    if (! is_file($private . '/.htaccess') || ! str_contains($private, 'wp-content/uploads/assoc-private')) {
        \WP_CLI::error('Private storage did not create an Apache guard under the web root.');
    }

    \WP_CLI::log('private storage wp-content/uploads/assoc-private with .htaccess; Apache only, not an Nginx proof');

    release_render([MeetingsPage::class, 'render'], ['Scratch board meeting'], 'meetings after create');
    release_render([DecisionsPage::class, 'render'], ['Scratch buys paper'], 'decisions after create');
    release_render([TasksPage::class, 'render'], ['Scratch call the hall'], 'tasks after create');

    $pageId = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'Scratch board page',
        'post_content' => '<!-- wp:foreningsplugin/current-board /-->',
    ]);
    $rendered = do_blocks((string) get_post_field('post_content', $pageId));

    if (! is_string($rendered) || ! str_contains($rendered, 'Anna Scratch')) {
        \WP_CLI::error('Current board block did not render Anna.');
    }

    \WP_CLI::log('front-end current board rendered');

    wp_set_current_user(0);
    $loggedOut = MemberAreaBlock::render();

    if (! str_contains($loggedOut, 'Logga in för att se ditt medlemskap.')) {
        \WP_CLI::error('Logged-out member area did not ask for login.');
    }

    release_admin();
    $account = WordpressMemberAccounts::service()->provision($personId, $today);

    if ($account->outcome !== AccountOutcome::Created || $account->wordpressUserId === null || $account->wordpressUserId < 1) {
        \WP_CLI::error('Member account was not provisioned: ' . $account->outcome->value . '.');
    }

    clean_user_cache($account->wordpressUserId);
    wp_set_current_user($account->wordpressUserId);
    $loggedIn = MemberAreaBlock::render();

    if (! str_contains($loggedIn, 'Anna Scratch') || ! str_contains($loggedIn, 'anna.scratch@example.test')) {
        \WP_CLI::error('Linked member area did not show Anna.');
    }

    \WP_CLI::log('member area logged-out prompt and linked Anna');
    release_admin();
}

function release_retained(): void
{
    $today = AssociationDate::fromIso(wp_date('Y-m-d'));

    if (WordpressAssociationProfile::load()->name() !== 'Scratch Test Association') {
        \WP_CLI::error('Association profile was lost on reactivation.');
    }

    $detail = null;

    foreach (WordpressPeople::directory()->peopleChoices() as $choice) {
        $candidate = WordpressPeople::directory()->personDetail((int) $choice['person_id'], $today);

        if (is_array($candidate) && ($candidate['email'] ?? '') === 'anna.scratch@example.test') {
            $detail = $candidate;
        }
    }

    if (! is_array($detail) || $detail['active_member'] !== true || ($detail['memberships'][0]['number'] ?? '') !== 'SCRATCH-1') {
        \WP_CLI::error('Anna was not retained after reactivation.');
    }

    $foundChair = false;

    foreach (WordpressBoard::service()->currentPublic($today) as $seat) {
        if ($seat->roleSlug() === 'chair' && $seat->personName() === 'Anna Scratch') {
            $foundChair = true;
        }
    }

    if (! $foundChair) {
        \WP_CLI::error('Current board assignment was not retained.');
    }

    $meetingId = null;

    foreach (WordpressMeetings::service()->listMeetings() as $meeting) {
        if ($meeting->title() === 'Scratch board meeting' && $meeting->status() === MeetingStatus::Held && $meeting->id() !== null) {
            $meetingId = $meeting->id();
        }
    }

    if ($meetingId === null) {
        \WP_CLI::error('Held meeting was not retained.');
    }

    $revision = WordpressMeetings::minutes()->current($meetingId);

    if ($revision === null || $revision->state() !== RevisionState::Finalized || ! str_contains($revision->body(), 'Scratch buys paper')) {
        \WP_CLI::error('Finalized minutes were not retained.');
    }

    \WP_CLI::log('reactivation retained profile, member, board, meeting, and finalized minutes');
}

release_admin();
release_locale();
release_schema();
release_tables();
release_seeds();
release_capabilities();
release_installed_tree();
release_blocks();
release_translations();

if (getenv('RELEASE_PHASE') === 'reactivate') {
    release_retained();
    \WP_CLI::success('Reactivation smoke passed.');

    return;
}

release_empty_pages();
release_flow();
\WP_CLI::success('Scratch install smoke passed.');
