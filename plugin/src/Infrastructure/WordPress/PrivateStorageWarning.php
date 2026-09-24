<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Access\Capabilities;

final class PrivateStorageWarning
{
    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
        add_action('admin_post_assoc_ack_private_storage', [self::class, 'acknowledge']);
        add_filter('site_status_tests', [self::class, 'registerSiteHealth']);
    }

    public static function needsAttention(): bool
    {
        return PrivateUploadDirectory::isInsideWebRoot() && get_option(PrivateUploadDirectory::ACK_OPTION) !== '1';
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION) || ! self::needsAttention()) {
            return;
        }

        echo '<div class="notice notice-warning"><p>' . esc_html__(
            'Skyddade föreningsfiler ligger under webbplatsens publika filkatalog. Hämtning går via en behörighetskontroll, men en besökare som kan gissa filsökvägen kan läsa filen direkt om webbservern inte blockerar katalogen. En .htaccess-fil kan stoppa det i Apache. Nginx läser inte den filen. Lägg katalogen utanför webbroten med FORENINGSPLUGIN_PRIVATE_DIR, eller blockera wp-content/uploads/assoc-private i webbservern.',
            'foreningsplugin'
        ) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_ack_private_storage">';
        wp_nonce_field('assoc_ack_private_storage');
        echo '<p><button type="submit" class="button">' . esc_html__('Webbservern blockerar katalogen', 'foreningsplugin') . '</button></p>';
        echo '</form></div>';
    }

    public static function acknowledge(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            wp_die(esc_html__('Du har inte behörighet att ändra den här inställningen.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer('assoc_ack_private_storage');
        update_option(PrivateUploadDirectory::ACK_OPTION, '1', false);
        wp_safe_redirect(admin_url('admin.php?page=foreningsplugin'));
        exit;
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public static function registerSiteHealth(array $tests): array
    {
        if (! isset($tests['direct']) || ! is_array($tests['direct'])) {
            $tests['direct'] = [];
        }

        $tests['direct']['foreningsplugin_private_files'] = [
            'label' => __('Skyddade föreningsfiler', 'foreningsplugin'),
            'test' => [self::class, 'siteStatus'],
        ];

        return $tests;
    }

    /**
     * @return array<string, mixed>
     */
    public static function siteStatus(): array
    {
        $inside = PrivateUploadDirectory::isInsideWebRoot();
        $acked = get_option(PrivateUploadDirectory::ACK_OPTION) === '1';
        $good = ! $inside || $acked;

        return [
            'label' => $good
                ? __('Skyddade föreningsfiler är placerade eller bekräftade.', 'foreningsplugin')
                : __('Skyddade föreningsfiler ligger under webbroten.', 'foreningsplugin'),
            'status' => $good ? 'good' : 'recommended',
            'badge' => [
                'label' => __('Säkerhet', 'foreningsplugin'),
                'color' => 'blue',
            ],
            'description' => '<p>' . esc_html($inside
                ? __('Filerna ligger i wp-content/uploads/assoc-private. En .htaccess-fil gäller Apache när servern tillåter den. Nginx läser inte den filen, så direkt åtkomst måste blockeras i serverkonfigurationen eller genom att flytta katalogen utanför webbroten.', 'foreningsplugin')
                : __('Filerna ligger utanför WordPress webbrot. Hämtning går fortfarande via behörighetskontrollen.', 'foreningsplugin')) . '</p>',
            'actions' => '',
            'test' => 'foreningsplugin_private_files',
        ];
    }
}
