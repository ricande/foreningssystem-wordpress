<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Privacy\RetentionPeriod;
use InvalidArgumentException;

final class RetentionPage
{
    public static function save(): void
    {
        self::guard('assoc_save_retention');

        $years = isset($_POST['years']) ? (int) $_POST['years'] : 0;

        try {
            WordpressRetention::save(new RetentionPeriod($years));
            self::redirect(['assoc_notice' => 'retention_saved']);
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to change the retention period.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (InvalidArgumentException) {
            self::redirect(['assoc_notice' => 'retention_invalid']);
        }
    }

    public static function apply(): void
    {
        self::guard('assoc_apply_retention_now');

        try {
            $result = WordpressRetention::applyToday();
            self::redirect([
                'assoc_notice' => 'retention_applied',
                'assoc_anonymized' => (string) $result->anonymized(),
                'assoc_audits' => (string) $result->removedAudits(),
            ]);
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to apply the retention.', 'foreningsplugin'), '', ['response' => 403]);
        }
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            wp_die(esc_html__('You do not have permission to view the retention.', 'foreningsplugin'), '', ['response' => 403]);
        }

        $years = WordpressRetention::load()->years();
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Retention', 'foreningsplugin') . '</h1>';
        echo '<p>' . esc_html__('Contact details are anonymized this many years after the membership has ended. Audit events are removed after the same time. Locked minutes and signed scans are kept.', 'foreningsplugin') . '</p>';
        self::notice();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_save_retention">';
        wp_nonce_field('assoc_save_retention');
        echo '<p><label>' . esc_html__('Years', 'foreningsplugin') . ' <input type="number" name="years" min="1" max="100" required value="' . esc_attr((string) $years) . '"></label></p>';
        echo '<p><button type="submit">' . esc_html__('Save retention', 'foreningsplugin') . '</button></p>';
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_apply_retention_now">';
        wp_nonce_field('assoc_apply_retention_now');
        echo '<p><button type="submit">' . esc_html__('Apply now', 'foreningsplugin') . '</button></p>';
        echo '</form>';
        echo '</div>';
    }

    private static function guard(string $nonce): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            wp_die(esc_html__('You do not have permission to change the retention period.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    /**
     * @param array<string, string> $args
     */
    private static function redirect(array $args): void
    {
        wp_safe_redirect(add_query_arg(array_merge([
            'page' => 'foreningsplugin-retention',
        ], $args), admin_url('admin.php')));
        exit;
    }

    private static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';

        if ($notice === 'retention_saved') {
            echo '<div class="notice notice-success"><p>' . esc_html__('The retention period is saved.', 'foreningsplugin') . '</p></div>';

            return;
        }

        if ($notice === 'retention_invalid') {
            echo '<div class="notice notice-error"><p>' . esc_html__('Enter a number of years between 1 and 100.', 'foreningsplugin') . '</p></div>';

            return;
        }

        if ($notice !== 'retention_applied') {
            return;
        }

        $anonymized = isset($_GET['assoc_anonymized']) ? absint($_GET['assoc_anonymized']) : 0;
        $audits = isset($_GET['assoc_audits']) ? absint($_GET['assoc_audits']) : 0;
        echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
            /* translators: 1: number of people anonymized, 2: number of audit events removed */
            __('The retention has been applied. %1$d people were anonymized. %2$d audit events were removed.', 'foreningsplugin'),
            $anonymized,
            $audits
        )) . '</p></div>';
    }
}
