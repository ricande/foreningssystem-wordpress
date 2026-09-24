<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

final class MeetingAdminForms
{
    /**
     * @param array<string, string> $fields
     */
    public static function post(string $action, int $meetingId, array $fields, string $label, ?string $confirm = null): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 0.5em 0.5em 0">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';

        foreach ($fields as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }

        wp_nonce_field($action);

        if ($confirm !== null) {
            echo '<label><input type="checkbox" name="confirm" value="1" required> ' . esc_html($confirm) . '</label> ';
        }

        submit_button($label, 'secondary', 'submit', false);
        echo '</form>';
    }

    public static function begin(string $action, int $meetingId): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
        wp_nonce_field($action);
    }

    /**
     * @param array<int, string> $people
     */
    public static function personSelect(string $name, array $people, string $label): void
    {
        echo '<p><label>' . esc_html($label) . ' <select name="' . esc_attr($name) . '">';
        echo '<option value="">' . esc_html__('None', 'foreningsplugin') . '</option>';

        foreach ($people as $id => $personName) {
            echo '<option value="' . esc_attr((string) $id) . '">' . esc_html($personName) . '</option>';
        }

        echo '</select></label></p>';
    }
}
