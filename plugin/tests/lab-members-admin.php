<?php

use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\ParticipantRole;
use Foreningssystem\Infrastructure\WordPress\MembersPage;
use Foreningssystem\Infrastructure\WordPress\WordpressBoard;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

require __DIR__ . '/lab-membership.php';

global $wpdb;

$people = $wpdb->prefix . 'assoc_person';
$organizations = $wpdb->prefix . 'assoc_organization';
$roles = $wpdb->prefix . 'assoc_board_role';
$assignments = $wpdb->prefix . 'assoc_board_assignment';
$relationships = $wpdb->prefix . 'assoc_guardian_relationship';
$approvals = $wpdb->prefix . 'assoc_guardian_approval';
$identity = $wpdb->prefix . 'assoc_personal_identity';
$identifier = '20120417-0011';

$cleanup = static function () use ($wpdb, $people, $organizations, $roles, $assignments, $relationships, $approvals, $identity): void {
    $ids = $wpdb->get_col("SELECT id FROM {$people} WHERE email LIKE 'lab-ux-%'");

    if (is_array($ids)) {
        foreach ($ids as $id) {
            $personId = (int) $id;
            $wpdb->delete($relationships, ['child_person_id' => $personId], ['%d']);
            $wpdb->delete($relationships, ['guardian_person_id' => $personId], ['%d']);
            $wpdb->delete($approvals, ['child_person_id' => $personId], ['%d']);
            $wpdb->delete($approvals, ['guardian_person_id' => $personId], ['%d']);
            $wpdb->delete($identity, ['person_id' => $personId], ['%d']);
            $wpdb->delete($assignments, ['person_id' => $personId], ['%d']);
        }
    }

    $companyId = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT organization_id FROM {$wpdb->prefix}assoc_membership WHERE membership_number = %s",
        'LAB-UX-C0042'
    ));
    lab_delete_membership_numbers('LAB-UX-%');

    if ($companyId > 0) {
        $wpdb->delete($organizations, ['id' => $companyId], ['%d']);
    }

    $wpdb->query("DELETE FROM {$people} WHERE email LIKE 'lab-ux-%'");
    $roleId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles} WHERE slug = %s", 'lab_ux_office'));

    if ($roleId > 0) {
        $wpdb->delete($assignments, ['role_id' => $roleId], ['%d']);
        $wpdb->delete($roles, ['id' => $roleId], ['%d']);
    }
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$render = static function (array $query): string {
    $_GET = $query;
    $_REQUEST = $query;
    ob_start();
    MembersPage::render();

    return (string) ob_get_clean();
};

$cleanup();
$secretary = get_user_by('login', 'lab-secretary');

if (! $secretary instanceof WP_User) {
    \WP_CLI::error('Missing lab user lab-secretary');
}

wp_set_current_user(1);
clean_user_cache(1);
$service = WordpressPeople::service();
$today = AssociationDate::fromIso(wp_date('Y-m-d'));
$start = AssociationDate::fromIso('2024-01-01');
$anna = $service->register('Anna', 'Andersson <b>', 'lab-ux-anna@example.test', 'LAB-UX-1042', 'ordinary', $start, null, $today);
$service->register('Karin', 'Andersson', 'lab-ux-karin@example.test', 'LAB-UX-F100', 'family', $start, null, $today);
$familyId = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$wpdb->prefix}assoc_membership WHERE membership_number = %s",
    'LAB-UX-F100'
));
$overlap = false;

try {
    $service->addParticipant($familyId, $anna, ParticipantRole::Member, false, $start);
} catch (MembershipRuleException $error) {
    $overlap = $error->getMessage() === 'Membership periods cannot overlap.';
}

$lisa = $service->addPersonToMembership(
    $familyId,
    'Lisa',
    'Andersson',
    'lab-ux-lisa@example.test',
    AssociationDate::fromIso('2012-04-17'),
    AssociationDate::fromIso('2020-01-01'),
    ParticipantRole::Member,
    false,
    $today
);
$service->endParticipation($familyId, $lisa, AssociationDate::fromIso('2022-12-31'));
$service->addParticipant($familyId, $lisa, ParticipantRole::Member, false, AssociationDate::fromIso('2025-03-01'));
$guardian = $service->rememberPerson('Eva', 'Andersson', 'lab-ux-eva@example.test', null, $today);
WordpressPeople::guardians()->relate($lisa, $guardian, 'parent', AssociationDate::fromIso('2012-04-17'));
WordpressPeople::guardians()->approve(
    $lisa,
    $guardian,
    'activity registration',
    'written note',
    new DateTimeImmutable('2026-01-15 00:00:00'),
    'written',
    'notice-1',
    '',
    1
);
WordpressPeople::identity()->store(
    $lisa,
    $identifier,
    'membership record',
    'guardian provided it',
    AssociationDate::fromIso('2026-09-24'),
    $today,
    1
);
$service->endParticipation($familyId, $lisa, AssociationDate::fromIso('2026-09-01'));
$beforeContact = $service->activeMemberCount($today);
$companyId = WordpressPeople::companies()->register(
    'Exempel UX AB',
    '556012-3456',
    'lab-ux-company@example.test',
    '',
    'LAB-UX-C0042',
    AssociationDate::fromIso('2025-01-01'),
    null
);
$service->addParticipant($companyId, $anna, ParticipantRole::Contact, false, AssociationDate::fromIso('2025-01-01'));
$afterContact = $service->activeMemberCount($today);
$duplicate = false;

try {
    $service->register('Ann', 'Annan', 'lab-ux-ann2@example.test', 'LAB-UX-1042', 'ordinary', $start, null, $today);
} catch (MembershipRuleException $error) {
    $duplicate = $error->getMessage() === 'Membership number is already used.';
}

$nils = $service->register('Nils', 'Nilsson', 'lab-ux-nils@example.test', 'LAB-UX-DEAD', 'ordinary', $start, null, $today);
$service->markDeceased($nils, $today);
$missingPerson = false;

try {
    $service->updateContact(999999999, 'Missing', 'Person', '', null, $today);
} catch (\RuntimeException $error) {
    $missingPerson = $error->getMessage() === 'Person was not found.';
}

$list = $render(['assoc_q' => 'LAB-UX-1042']);
$byName = $render(['assoc_q' => 'Karin', 'assoc_kind' => 'family']);
$companyList = $render(['assoc_q' => 'LAB-UX-C0042', 'assoc_kind' => 'company']);
$history = $render(['assoc_q' => 'lab-ux-lisa@example.test', 'assoc_state' => 'history']);
$notActive = $render(['assoc_q' => 'lab-ux-lisa@example.test', 'assoc_state' => 'active']);
$empty = $render(['assoc_q' => 'lab-ux-does-not-exist']);
$ordinaryForm = $render(['assoc_new' => 'ordinary']);
$youthForm = $render(['assoc_new' => 'youth']);
$familyForm = $render(['assoc_new' => 'family']);
$companyForm = $render(['assoc_new' => 'company']);
$lisaPage = $render(['assoc_person' => (string) $lisa]);
$familyPage = $render(['assoc_number' => 'LAB-UX-F100']);
$companyPage = $render(['assoc_number' => 'LAB-UX-C0042']);
$annaPage = $render(['assoc_person' => (string) $anna]);
$nilsPage = $render(['assoc_person' => (string) $nils]);
$overlapNotice = $render(['assoc_notice' => 'overlap', 'assoc_number' => 'LAB-UX-F100']);
wp_set_current_user((int) $secretary->ID);
clean_user_cache((int) $secretary->ID);
$secretaryList = $render([]);
$secretaryLisa = $render(['assoc_person' => (string) $lisa]);
$secretaryCannotEdit = ! str_contains($secretaryLisa, 'assoc_store_identity') && ! str_contains($secretaryList, 'assoc_register_person');
wp_set_current_user(1);
clean_user_cache(1);
$periodId = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$wpdb->prefix}assoc_membership_period WHERE membership_id = (
        SELECT id FROM {$wpdb->prefix}assoc_membership WHERE membership_number = %s
    ) AND ended_on IS NULL",
    'LAB-UX-1042'
));
$wpdb->insert($roles, [
    'slug' => 'lab_ux_office',
    'name' => 'UX office',
    'allows_multiple' => 1,
    'sort_order' => 901,
], ['%s', '%s', '%d', '%d']);
$roleId = (int) $wpdb->insert_id;
WordpressBoard::service()->place($anna, $roleId, $start, null, '', '');
$notices = $service->endMembership($periodId, AssociationDate::fromIso('2026-09-24'));
MembersPage::rememberBoardEffects($notices);
$ended = $render(['assoc_notice' => 'ended', 'assoc_person' => (string) $anna]);
$nonceFailed = false;
$capabilityFailed = false;
$_POST = [];
$_REQUEST = [];
add_filter('wp_die_handler', static function () {
    return static function (): void {
        throw new \RuntimeException('wp_die');
    };
}, 1000);

try {
    MembersPage::updatePerson();
} catch (\RuntimeException $error) {
    $nonceFailed = $error->getMessage() === 'wp_die';
}

wp_set_current_user((int) $secretary->ID);
clean_user_cache((int) $secretary->ID);

try {
    MembersPage::storeIdentity();
} catch (\RuntimeException $error) {
    $capabilityFailed = $error->getMessage() === 'wp_die';
}

remove_all_filters('wp_die_handler');
wp_set_current_user(1);
clean_user_cache(1);

$checks = [
    'overlap' => $overlap === true,
    'duplicate' => $duplicate === true,
    'missing person' => $missingPerson === true,
    'contact does not change the individual count' => $beforeContact === $afterContact,
    'nonce' => $nonceFailed === true,
    'capability' => $capabilityFailed === true,
    'secretary cannot edit' => $secretaryCannotEdit === true,
    'list number' => str_contains($list, 'LAB-UX-1042'),
    'escaped name' => str_contains($list, 'Andersson &lt;b&gt;') && ! str_contains($list, 'Andersson <b>'),
    'list hides identity' => ! str_contains($list, $identifier),
    'family search' => str_contains($byName, 'LAB-UX-F100'),
    'company search' => str_contains($companyList, 'Exempel UX AB') && ! str_contains($companyList, 'LAB-UX-1042'),
    'history' => str_contains($history, 'Lisa Andersson') && str_contains($history, 'LAB-UX-F100'),
    'inactive filter' => ! str_contains($notActive, 'Lisa Andersson'),
    'empty search' => str_contains($empty, 'Inga medlemmar matchar den här sökningen.'),
    'ordinary form' => str_contains($ordinaryForm, 'name="action" value="assoc_register_person"') && str_contains($ordinaryForm, 'name="action" value="assoc_open_membership"') && ! str_contains($ordinaryForm, 'name="birth_date" value="" required'),
    'youth form' => str_contains($youthForm, 'name="birth_date" value="" required') && str_contains($youthForm, 'Ett personnummer krävs inte.'),
    'family form' => str_contains($familyForm, 'name="membership_kind" value="family"'),
    'company form' => str_contains($companyForm, 'name="action" value="assoc_register_company"') && str_contains($companyForm, 'En företagskontakt är inte en enskild medlem.'),
    'lisa history' => str_contains($lisaPage, '2012-04-17') && str_contains($lisaPage, '2020-01-01') && str_contains($lisaPage, '2022-12-31') && str_contains($lisaPage, '2025-03-01') && str_contains($lisaPage, '2026-09-01'),
    'authorized identity' => str_contains($lisaPage, $identifier) && str_contains($lisaPage, 'activity registration') && str_contains($lisaPage, 'Eva Andersson'),
    'no membership id label' => ! str_contains($lisaPage, '>Membership id<'),
    'family actions' => str_contains($familyPage, 'assoc_add_family_participant') && str_contains($familyPage, 'assoc_end_participation'),
    'company actions' => str_contains($companyPage, 'assoc_add_company_contact') && str_contains($companyPage, 'assoc_end_participation') && str_contains($companyPage, 'Företagskontakter är inte enskilda medlemmar.'),
    'anna actions' => str_contains($annaPage, 'assoc_end_membership') && str_contains($annaPage, 'assoc_mark_deceased'),
    'deceased form hidden' => ! str_contains($nilsPage, 'assoc_mark_deceased'),
    'overlap notice' => str_contains($overlapNotice, 'Personen har redan överlappande aktivt medlemskap.'),
    'secretary identity hidden' => ! str_contains($secretaryList, $identifier) && ! str_contains($secretaryLisa, $identifier) && str_contains($secretaryLisa, 'Personnummer: Registrerat'),
    'board outcome' => str_contains($ended, 'UX office') && str_contains($ended, '2026-09-24') && str_contains($ended, 'Medlemskapet är avslutat. Medlemshistoriken behålls.'),
];
$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => $passed !== true));

if ($failed !== []) {
    $fail('Members admin lab failed: ' . implode(', ', $failed));
}

$cleanup();
\WP_CLI::success('Members admin UX passed.');
