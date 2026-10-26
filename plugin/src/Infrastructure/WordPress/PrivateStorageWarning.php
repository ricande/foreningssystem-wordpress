<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Infrastructure\Files\PrivateStorageExposure;

final class PrivateStorageWarning
{
    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
        add_action('admin_post_assoc_ack_private_storage', [self::class, 'acknowledge']);
        add_action('admin_post_assoc_recheck_private_storage', [self::class, 'recheck']);
        add_filter('site_status_tests', [self::class, 'registerSiteHealth']);
    }

    public static function needsAttention(): bool
    {
        if (! PrivateUploadDirectory::isInsideWebRoot()) {
            return false;
        }

        return PrivateStorageExposure::needsAttention(true, PrivateStorageProbe::result(), self::acknowledged());
    }

    /**
     * The web server was seen serving a file from the private directory. No acknowledgement
     * hides that.
     */
    public static function isExposed(): bool
    {
        return PrivateUploadDirectory::isInsideWebRoot() && PrivateStorageProbe::result() === PrivateStorageExposure::EXPOSED;
    }

    private static function acknowledged(): bool
    {
        return get_option(PrivateUploadDirectory::ACK_OPTION) === '1';
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION) || ! self::needsAttention()) {
            return;
        }

        if (self::isExposed()) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__(
                'Protected association files can be read directly from the web.',
                'foreningsplugin'
            ) . '</strong> ' . esc_html__(
                'The web server returned a test file from the private directory to a request without a login. Anyone who knows a file address can read that file without any authorization check. Put the directory outside the web root with FORENINGSPLUGIN_PRIVATE_DIR, or block wp-content/uploads/assoc-private in the web server. This warning goes away when a new check shows the directory is blocked.',
                'foreningsplugin'
            ) . ' ' . esc_html__(
                'Until then, new documents and signed copies are not saved, and minutes PDFs are delivered without being stored.',
                'foreningsplugin'
            ) . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_recheck_private_storage">';
            wp_nonce_field('assoc_recheck_private_storage');
            echo '<p><button type="submit" class="button">' . esc_html__('Check again', 'foreningsplugin') . '</button></p>';
            echo '</form></div>';

            return;
        }

        echo '<div class="notice notice-warning"><p>' . esc_html__(
            'Protected association files are inside the public web root. Downloads check authorization, but a visitor who can guess the file address can read the file directly unless the web server blocks the directory. An .htaccess file can stop that on Apache. Nginx does not read that file. Put the directory outside the web root with FORENINGSPLUGIN_PRIVATE_DIR, or block wp-content/uploads/assoc-private in the web server.',
            'foreningsplugin'
        ) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_ack_private_storage">';
        wp_nonce_field('assoc_ack_private_storage');
        echo '<p><button type="submit" class="button">' . esc_html__('The web server blocks the directory', 'foreningsplugin') . '</button></p>';
        echo '</form></div>';
    }

    public static function acknowledge(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            wp_die(esc_html__('You are not allowed to change this setting.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer('assoc_ack_private_storage');

        // The acknowledgement is checked against the web server, not taken on trust.
        if (PrivateStorageProbe::result(true) !== PrivateStorageExposure::EXPOSED) {
            update_option(PrivateUploadDirectory::ACK_OPTION, '1', false);
        }

        wp_safe_redirect(admin_url('admin.php?page=foreningsplugin'));
        exit;
    }

    /**
     * Ask the web server again, after the administrator changed its configuration. This
     * never records an acknowledgement.
     */
    public static function recheck(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            wp_die(esc_html__('You are not allowed to change this setting.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer('assoc_recheck_private_storage');
        PrivateStorageProbe::result(true);
        $back = wp_get_referer();
        wp_safe_redirect(is_string($back) && $back !== '' ? $back : admin_url('admin.php?page=foreningsplugin'));
        exit;
    }

    /**
     * Where protected files stand right now, shown whether or not anything is wrong, with
     * when and through which address the web server was last asked.
     */
    public static function statusBox(): string
    {
        $report = PrivateStorageProbe::report();
        $acknowledged = self::acknowledged();
        $checked = $report['checked_at'] > 0
            ? sprintf(
                /* translators: 1: date and time of the check, 2: the address that was asked */
                __('Checked %1$s through %2$s.', 'foreningsplugin'),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), $report['checked_at']),
                $report['address'] !== '' ? $report['address'] : __('no address that answered', 'foreningsplugin')
            )
            : '';

        if ($report['outside']) {
            $class = 'notice-success';
            $text = __('Protected files are stored outside the web root. The web server cannot serve them directly.', 'foreningsplugin');
        } elseif ($report['result'] === PrivateStorageExposure::BLOCKED) {
            $class = 'notice-success';
            $text = __('Protected files are safe: the web server refused direct requests for test files of every stored type.', 'foreningsplugin');
        } elseif ($report['result'] === PrivateStorageExposure::EXPOSED) {
            $class = 'notice-error';
            $text = __('Protected files are not safe: the web server served a test file to a request without a login. New documents and signed copies are not saved until the directory is blocked or moved.', 'foreningsplugin');
        } elseif ($acknowledged) {
            $class = 'notice-warning';
            $text = __('The web server could not be asked automatically. An administrator has confirmed that it blocks the directory.', 'foreningsplugin');
        } else {
            $class = 'notice-warning';
            $text = __('The web server could not be asked automatically, so it is not known whether protected files can be read directly.', 'foreningsplugin');
        }

        $html = '<div class="notice inline ' . esc_attr($class) . ' assoc-private-storage-status"><p><strong>'
            . esc_html__('Protected files', 'foreningsplugin') . ':</strong> ' . esc_html($text);

        if ($checked !== '') {
            $html .= ' ' . esc_html($checked);
        }

        $html .= '</p>';

        if (! $report['outside'] && current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                . '<input type="hidden" name="action" value="assoc_recheck_private_storage">'
                . wp_nonce_field('assoc_recheck_private_storage', '_wpnonce', true, false)
                . '<p><button type="submit" class="button">' . esc_html__('Check again', 'foreningsplugin') . '</button></p></form>';
        }

        return $html . '</div>';
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
            'label' => __('Protected association files', 'foreningsplugin'),
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
        $probe = $inside ? PrivateStorageProbe::result(true) : PrivateStorageExposure::BLOCKED;
        $exposed = $probe === PrivateStorageExposure::EXPOSED;
        $good = ! PrivateStorageExposure::needsAttention($inside, $probe, self::acknowledged());

        if ($exposed) {
            return [
                'label' => __('Protected association files can be read directly from the web.', 'foreningsplugin'),
                'status' => 'critical',
                'badge' => [
                    'label' => __('Security', 'foreningsplugin'),
                    'color' => 'red',
                ],
                'description' => '<p>' . esc_html__(
                    'The web server returned a test file from wp-content/uploads/assoc-private to a request without a login. Block the directory in the web server or move it outside the web root with FORENINGSPLUGIN_PRIVATE_DIR.',
                    'foreningsplugin'
                ) . '</p>',
                'actions' => '',
                'test' => 'foreningsplugin_private_files',
            ];
        }

        return [
            'label' => $good
                ? __('Protected association files are placed or confirmed.', 'foreningsplugin')
                : __('Protected association files are inside the web root.', 'foreningsplugin'),
            'status' => $good ? 'good' : 'recommended',
            'badge' => [
                'label' => __('Security', 'foreningsplugin'),
                'color' => 'blue',
            ],
            'description' => '<p>' . esc_html(match (true) {
                ! $inside => __('The files are outside the WordPress web root. Downloads still go through the authorization check.', 'foreningsplugin'),
                $probe === PrivateStorageExposure::BLOCKED => __('Protected files are safe: the web server refused direct requests for test files of every stored type.', 'foreningsplugin'),
                default => __('The files are in wp-content/uploads/assoc-private. An .htaccess file applies to Apache when the server allows it. Nginx does not read that file, so direct access has to be blocked in the server configuration or by moving the directory outside the web root.', 'foreningsplugin'),
            }) . '</p>',
            'actions' => '',
            'test' => 'foreningsplugin_private_files',
        ];
    }
}
