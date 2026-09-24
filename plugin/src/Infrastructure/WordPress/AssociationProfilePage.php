<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Association\AssociationProfile;
use Foreningssystem\Domain\Membership\AssociationDate;
use InvalidArgumentException;

final class AssociationProfilePage
{
    public static function save(): void
    {
        self::guard();

        try {
            WordpressAssociationProfile::save(self::posted());
            self::redirect('profile_saved');
        } catch (NotAllowed) {
            wp_die(esc_html__('Du har inte behörighet att ändra föreningsprofilen.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (InvalidArgumentException) {
            self::redirect('profile_invalid');
        }
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            wp_die(esc_html__('Du har inte behörighet att se föreningsprofilen.', 'foreningsplugin'), '', ['response' => 403]);
        }

        wp_enqueue_media();
        wp_enqueue_script(
            'foreningsplugin-profile',
            plugins_url('assets/profile.js', dirname(__DIR__, 3) . '/foreningsplugin.php'),
            ['jquery', 'media-editor'],
            Plugin::VERSION,
            true
        );

        $profile = WordpressAssociationProfile::load();
        $year = $profile->membershipYear(AssociationDate::fromIso(wp_date('Y-m-d')));
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Profil', 'foreningsplugin') . '</h1>';
        echo '<p>' . esc_html__('Föreningens namn, kontaktuppgifter och när verksamhetsåret börjar. Logotypen är en bild i mediabiblioteket. Ett verksamhetsår kan börja en annan dag än den 1 januari. Inställningen ändrar inte medlemsperioder som redan finns.', 'foreningsplugin') . '</p>';
        self::notice();
        echo '<p>' . esc_html(sprintf(
            /* translators: 1: start date, 2: end date */
            __('Pågående verksamhetsår: %1$s–%2$s', 'foreningsplugin'),
            $year->startedOn()->iso(),
            $year->endedOn()->iso()
        )) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_save_profile">';
        wp_nonce_field('assoc_save_profile');
        echo '<table class="form-table"><tbody>';
        self::textRow('name', __('Namn', 'foreningsplugin'), $profile->name());
        self::textRow('organization_number', __('Organisationsnummer', 'foreningsplugin'), $profile->organizationNumber());
        echo '<tr><th scope="row"><label for="assoc-address">' . esc_html__('Adress', 'foreningsplugin') . '</label></th><td>';
        echo '<textarea name="address" id="assoc-address" rows="3" cols="40">' . esc_textarea($profile->address()) . '</textarea></td></tr>';
        self::textRow('email', __('E-post', 'foreningsplugin'), $profile->email());
        self::textRow('phone', __('Telefon', 'foreningsplugin'), $profile->phone());
        echo '<tr><th scope="row"><label for="assoc-language">' . esc_html__('Språk', 'foreningsplugin') . '</label></th><td><select name="language" id="assoc-language">';
        echo '<option value="sv"' . selected($profile->language(), AssociationProfile::LANGUAGE_SWEDISH, false) . '>' . esc_html__('Svenska', 'foreningsplugin') . '</option>';
        echo '<option value="en"' . selected($profile->language(), AssociationProfile::LANGUAGE_ENGLISH, false) . '>' . esc_html__('Engelska', 'foreningsplugin') . '</option>';
        echo '</select></td></tr>';
        self::logoRow($profile);
        self::startRow($profile);
        echo '</tbody></table>';
        echo '<p><button type="submit">' . esc_html__('Spara profil', 'foreningsplugin') . '</button></p>';
        echo '</form></div>';
    }

    private static function posted(): AssociationProfile
    {
        $logo = isset($_POST['logo_attachment_id']) ? absint($_POST['logo_attachment_id']) : 0;

        return new AssociationProfile(
            sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? ''))),
            sanitize_text_field(wp_unslash((string) ($_POST['organization_number'] ?? ''))),
            sanitize_textarea_field(wp_unslash((string) ($_POST['address'] ?? ''))),
            sanitize_email(wp_unslash((string) ($_POST['email'] ?? ''))),
            sanitize_text_field(wp_unslash((string) ($_POST['phone'] ?? ''))),
            sanitize_key((string) ($_POST['language'] ?? '')),
            $logo > 0 ? $logo : null,
            isset($_POST['membership_year_month']) ? (int) $_POST['membership_year_month'] : 0,
            isset($_POST['membership_year_day']) ? (int) $_POST['membership_year_day'] : 0
        );
    }

    private static function guard(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            wp_die(esc_html__('Du har inte behörighet att ändra föreningsprofilen.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer('assoc_save_profile');
    }

    private static function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => 'foreningsplugin-profile',
            'assoc_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    private static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';

        if ($notice === 'profile_saved') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Föreningsprofilen är sparad.', 'foreningsplugin') . '</p></div>';

            return;
        }

        if ($notice === 'profile_invalid') {
            echo '<div class="notice notice-error"><p>' . esc_html__('Profilen kunde inte sparas. Kontrollera e-post, telefon, språk, logotyp och startdag.', 'foreningsplugin') . '</p></div>';
        }
    }

    private static function textRow(string $name, string $label, string $value): void
    {
        echo '<tr><th scope="row"><label for="assoc-' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input type="text" class="regular-text" name="' . esc_attr($name) . '" id="assoc-' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        echo '</td></tr>';
    }

    private static function logoRow(AssociationProfile $profile): void
    {
        $logo = $profile->logoAttachmentId();
        echo '<tr><th scope="row">' . esc_html__('Logotyp', 'foreningsplugin') . '</th><td>';
        echo '<input type="hidden" name="logo_attachment_id" id="assoc-logo-id" value="' . esc_attr($logo === null ? '' : (string) $logo) . '">';

        if ($logo !== null && wp_attachment_is_image($logo)) {
            echo wp_get_attachment_image($logo, 'thumbnail');
        }

        echo '<p><button type="button" class="button" id="assoc-choose-logo">' . esc_html__('Välj bild', 'foreningsplugin') . '</button> ';
        echo '<button type="button" class="button" id="assoc-clear-logo">' . esc_html__('Ta bort bild', 'foreningsplugin') . '</button></p>';
        echo '</td></tr>';
    }

    private static function startRow(AssociationProfile $profile): void
    {
        echo '<tr><th scope="row">' . esc_html__('Verksamhetsåret börjar', 'foreningsplugin') . '</th><td>';
        echo '<label>' . esc_html__('Månad', 'foreningsplugin') . ' <select name="membership_year_month">';

        foreach (self::months() as $number => $label) {
            echo '<option value="' . esc_attr((string) $number) . '"' . selected($profile->membershipYearStartMonth(), $number, false) . '>' . esc_html($label) . '</option>';
        }

        echo '</select></label> ';
        echo '<label>' . esc_html__('Dag', 'foreningsplugin') . ' <input type="number" name="membership_year_day" min="1" max="31" required value="' . esc_attr((string) $profile->membershipYearStartDay()) . '"></label>';
        echo '</td></tr>';
    }

    /**
     * @return array<int, string>
     */
    private static function months(): array
    {
        return [
            1 => __('januari', 'foreningsplugin'),
            2 => __('februari', 'foreningsplugin'),
            3 => __('mars', 'foreningsplugin'),
            4 => __('april', 'foreningsplugin'),
            5 => __('maj', 'foreningsplugin'),
            6 => __('juni', 'foreningsplugin'),
            7 => __('juli', 'foreningsplugin'),
            8 => __('augusti', 'foreningsplugin'),
            9 => __('september', 'foreningsplugin'),
            10 => __('oktober', 'foreningsplugin'),
            11 => __('november', 'foreningsplugin'),
            12 => __('december', 'foreningsplugin'),
        ];
    }

}
