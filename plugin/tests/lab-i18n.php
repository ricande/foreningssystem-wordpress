<?php

use Foreningssystem\Infrastructure\WordPress\MeetingDetailPage;

$englishProfile = 'Profile';
$englishMembers = 'Active members: %d';
$englishYear = 'Current membership year: %1$s–%2$s';

switch_to_locale('en_US');
$profile = __('Profile', 'foreningsplugin');
$members = __('Active members: %d', 'foreningsplugin');
$year = __('Current membership year: %1$s–%2$s', 'foreningsplugin');
$englishLanguage = MeetingDetailPage::htmlLanguage();
restore_previous_locale();

switch_to_locale('sv_SE');
$swedishProfile = __('Profile', 'foreningsplugin');
$swedishMembers = __('Active members: %d', 'foreningsplugin');
$swedishYear = __('Current membership year: %1$s–%2$s', 'foreningsplugin');
$swedishPage = __('Page %1$d / %2$d', 'foreningsplugin');
$swedishLanguage = MeetingDetailPage::htmlLanguage();
restore_previous_locale();

if (
    $profile !== $englishProfile
    || $members !== $englishMembers
    || $year !== $englishYear
    || $englishLanguage !== 'en-US'
    || $swedishProfile !== 'Profil'
    || $swedishMembers !== 'Aktiva medlemmar: %d'
    || $swedishYear !== 'Pågående verksamhetsår: %1$s–%2$s'
    || $swedishPage !== 'Sida %1$d / %2$d'
    || $swedishLanguage !== 'sv-SE'
) {
    \WP_CLI::error('The locale did not switch the association screens and the print language.');
}

\WP_CLI::success('Swedish and English locales both reach the association screens.');
