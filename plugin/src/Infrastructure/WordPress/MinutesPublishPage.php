<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Access\RoleBundles;
use InvalidArgumentException;

final class MinutesPublishPage
{
    public static function save(): void
    {
        self::guard();
        $roles = [];

        if (isset($_POST['publish_roles']) && is_array($_POST['publish_roles'])) {
            foreach ($_POST['publish_roles'] as $role) {
                $roles[] = sanitize_key((string) $role);
            }
        }

        try {
            WordpressMinutesPublish::update($roles);
            self::redirect('minutes_publish_saved');
        } catch (NotAllowed) {
            wp_die(esc_html__('Du har inte behörighet att ändra vem som får publicera protokoll.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (InvalidArgumentException) {
            self::redirect('minutes_publish_invalid');
        }
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            wp_die(esc_html__('Du har inte behörighet att se vem som får publicera protokoll.', 'foreningsplugin'), '', ['response' => 403]);
        }

        $setting = WordpressAccess::load();
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Publicera protokoll', 'foreningsplugin') . '</h1>';
        echo '<p>' . esc_html__('Ordföranden får publicera ett låst protokoll från början. Föreningen kan ge samma behörighet till en annan roll. Publicering visar texten på webbplatsen och lämnar protokollet oförändrat. Webbplatsens administratör behåller behörigheten och kan ändra tillbaka.', 'foreningsplugin') . '</p>';
        self::notice();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_save_minutes_publish">';
        wp_nonce_field('assoc_save_minutes_publish');

        foreach (self::labels() as $role => $label) {
            $checked = in_array(Capabilities::PUBLISH_MINUTES, $setting->capabilitiesFor($role), true);
            echo '<p><label><input type="checkbox" name="publish_roles[]" value="' . esc_attr($role) . '"' . checked($checked, true, false) . '> ' . esc_html($label) . '</label></p>';
        }

        echo '<p><button type="submit">' . esc_html__('Spara behörighet', 'foreningsplugin') . '</button></p>';
        echo '</form></div>';
    }

    private static function guard(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            wp_die(esc_html__('Du har inte behörighet att ändra vem som får publicera protokoll.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer('assoc_save_minutes_publish');
    }

    private static function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => 'foreningsplugin-minutes-publish',
            'assoc_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    private static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';

        if ($notice === 'minutes_publish_saved') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Behörigheten att publicera protokoll är sparad.', 'foreningsplugin') . '</p></div>';

            return;
        }

        if ($notice === 'minutes_publish_invalid') {
            echo '<div class="notice notice-error"><p>' . esc_html__('Rollen kunde inte sparas.', 'foreningsplugin') . '</p></div>';
        }
    }

    /**
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            RoleBundles::SECRETARY => __('Sekreterare', 'foreningsplugin'),
            RoleBundles::CHAIR => __('Ordförande', 'foreningsplugin'),
            RoleBundles::TREASURER => __('Kassör', 'foreningsplugin'),
            RoleBundles::BOARD_MEMBER => __('Styrelseledamot', 'foreningsplugin'),
        ];
    }
}
