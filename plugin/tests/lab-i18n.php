<?php

switch_to_locale('en_US');

$profile = __('Profil', 'foreningsplugin');
$members = __('Aktiva medlemmar: %d', 'foreningsplugin');
$year = __('Pågående verksamhetsår: %1$s–%2$s', 'foreningsplugin');

restore_previous_locale();

if ($profile !== 'Profile' || $members !== 'Active members: %d' || $year !== 'Current membership year: %1$s–%2$s') {
    \WP_CLI::error('The English catalog did not translate the association screens.');
}

\WP_CLI::success('English translations are loaded for the association screens.');
