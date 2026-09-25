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
            wp_die(esc_html__('You do not have permission to change who may publish minutes.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (InvalidArgumentException) {
            self::redirect('minutes_publish_invalid');
        }
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            wp_die(esc_html__('You do not have permission to see who may publish minutes.', 'foreningsplugin'), '', ['response' => 403]);
        }

        $setting = WordpressAccess::load();
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Publish minutes', 'foreningsplugin') . '</h1>';
        AssociationSettingsPage::backToHub();
        echo '<p>' . esc_html__('The chair may publish locked minutes from the start. The association can give the same permission to another role. Publishing shows the text on the site and leaves the minutes unchanged. The site administrator keeps the permission and can change it back.', 'foreningsplugin') . '</p>';
        self::notice();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_save_minutes_publish">';
        wp_nonce_field('assoc_save_minutes_publish');

        foreach (self::labels() as $role => $label) {
            $checked = in_array(Capabilities::PUBLISH_MINUTES, $setting->capabilitiesFor($role), true);
            echo '<p><label><input type="checkbox" name="publish_roles[]" value="' . esc_attr($role) . '"' . checked($checked, true, false) . '> ' . esc_html($label) . '</label></p>';
        }

        echo '<p><button type="submit">' . esc_html__('Save permission', 'foreningsplugin') . '</button></p>';
        echo '</form></div>';
    }

    private static function guard(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            wp_die(esc_html__('You do not have permission to change who may publish minutes.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer('assoc_save_minutes_publish');
    }

    private static function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => AssociationSettingsPage::PAGE,
            'section' => AssociationSettingsPage::SECTION_MINUTES_PUBLISH,
            'assoc_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    private static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';

        if ($notice === 'minutes_publish_saved') {
            echo '<div class="notice notice-success"><p>' . esc_html__('The permission to publish minutes is saved.', 'foreningsplugin') . '</p></div>';

            return;
        }

        if ($notice === 'minutes_publish_invalid') {
            echo '<div class="notice notice-error"><p>' . esc_html__('The role could not be saved.', 'foreningsplugin') . '</p></div>';
        }
    }

    /**
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            RoleBundles::SECRETARY => __('Secretary', 'foreningsplugin'),
            RoleBundles::CHAIR => __('Chair', 'foreningsplugin'),
            RoleBundles::TREASURER => __('Treasurer', 'foreningsplugin'),
            RoleBundles::BOARD_MEMBER => __('Board member', 'foreningsplugin'),
        ];
    }
}
