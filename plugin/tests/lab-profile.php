<?php

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Association\AssociationProfile;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\WordPress\AssociationProfilePage;
use Foreningssystem\Infrastructure\WordPress\Plugin;
use Foreningssystem\Infrastructure\WordPress\WordpressAssociationProfile;

$cleanup = static function (): void {
    wp_set_current_user(1);
    clean_user_cache(1);
    WordpressAssociationProfile::save(AssociationProfile::empty());
};

$fail = static function (string $message) use ($cleanup): void {
    $cleanup();
    \WP_CLI::error($message);
};

$cleanup();
$secretary = get_user_by('login', 'lab-secretary');

if (! $secretary instanceof WP_User) {
    \WP_CLI::error('Missing lab user lab-secretary');
}

clean_user_cache((int) $secretary->ID);
wp_set_current_user((int) $secretary->ID);
$denied = false;

try {
    WordpressAssociationProfile::save(new AssociationProfile(
        'LAB-PROFILE',
        '802001-1234',
        "Storgatan 1\n111 22 Stan",
        'styrelse@example.test',
        '08-100 00',
        AssociationProfile::LANGUAGE_ENGLISH,
        null,
        7,
        1
    ));
} catch (NotAllowed $error) {
    $denied = $error->getMessage() === Capabilities::MANAGE_ASSOCIATION;
}

wp_set_current_user(1);
clean_user_cache(1);
$logoRejected = false;

try {
    WordpressAssociationProfile::save(new AssociationProfile(
        'LAB-PROFILE',
        '',
        '',
        '',
        '',
        AssociationProfile::LANGUAGE_SWEDISH,
        999999,
        1,
        1
    ));
} catch (\InvalidArgumentException) {
    $logoRejected = true;
}

$profile = new AssociationProfile(
    'LAB-PROFILE',
    '802001-1234',
    "Storgatan 1\n111 22 Stan",
    'styrelse@example.test',
    '08-100 00',
    AssociationProfile::LANGUAGE_ENGLISH,
    null,
    7,
    1
);
WordpressAssociationProfile::save($profile);
$loaded = WordpressAssociationProfile::load();
$year = $loaded->membershipYear(AssociationDate::fromIso('2026-09-24'));
ob_start();
AssociationProfilePage::render();
$html = (string) ob_get_clean();
ob_start();
Plugin::renderAdminPage();
$overview = (string) ob_get_clean();

if (
    $denied !== true
    || $logoRejected !== true
    || $loaded->name() !== 'LAB-PROFILE'
    || $loaded->organizationNumber() !== '802001-1234'
    || $loaded->address() !== "Storgatan 1\n111 22 Stan"
    || $loaded->email() !== 'styrelse@example.test'
    || $loaded->phone() !== '08-100 00'
    || $loaded->language() !== AssociationProfile::LANGUAGE_ENGLISH
    || $loaded->logoAttachmentId() !== null
    || $loaded->membershipYearStart() !== '07-01'
    || $year->startedOn()->iso() !== '2026-07-01'
    || $year->endedOn()->iso() !== '2027-06-30'
    || ! str_contains($html, 'Ett verksamhetsår kan börja en annan dag än den 1 januari')
    || ! str_contains($html, 'value="LAB-PROFILE"')
    || ! str_contains($html, 'value="en" selected')
    || ! str_contains($overview, 'LAB-PROFILE')
) {
    $fail('The association profile was not saved separately from membership periods.');
}

$cleanup();
$restored = WordpressAssociationProfile::load();

if ($restored->name() !== '' || $restored->language() !== AssociationProfile::LANGUAGE_SWEDISH || $restored->membershipYearStart() !== '01-01') {
    \WP_CLI::error('The empty association profile was not restored.');
}

\WP_CLI::success('The association profile stores name, contact, language, and membership-year start.');
