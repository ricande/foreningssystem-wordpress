<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Account\AccountOutcome;
use Foreningssystem\Application\Account\MemberAccountStatus;

final class MemberAccountSection
{
    public static function render(int $personId, bool $canEdit): void
    {
        $status = WordpressMemberAccounts::status($personId);
        echo '<h2>' . esc_html__('Member account', 'foreningsplugin') . '</h2>';
        echo '<p><strong>' . esc_html__('Status', 'foreningsplugin') . ':</strong> ' . esc_html(self::label($status->outcome)) . '</p>';

        if ($status->outcome === AccountOutcome::AlreadyLinked) {
            self::linked($status, $personId, $canEdit);

            return;
        }

        if ($status->outcome === AccountOutcome::KnownMinor) {
            echo '<p>' . esc_html__('No account is created automatically for members under 18.', 'foreningsplugin') . '</p>';

            return;
        }

        if ($status->personEmail !== '') {
            echo '<p>' . esc_html__('Email', 'foreningsplugin') . ': ' . esc_html($status->personEmail) . '</p>';
        }

        echo '<p>' . esc_html(self::reason($status->outcome)) . '</p>';

        if ($status->outcome === AccountOutcome::WordpressEmailConflict && is_string($status->accountName) && is_string($status->accountEmail)) {
            echo '<p>' . esc_html__('Existing account', 'foreningsplugin') . ': ' . esc_html($status->accountName . ' (' . $status->accountEmail . ')') . '</p>';

            if ($canEdit && $status->wordpressUserId !== null) {
                self::linkForm($personId, $status->wordpressUserId, __('Link this account', 'foreningsplugin'));
            }
        }

        if (! $canEdit || $status->outcome === AccountOutcome::KnownMinor) {
            return;
        }

        $pendingUser = isset($_GET['assoc_account_user']) ? absint($_GET['assoc_account_user']) : 0;

        if ($pendingUser > 0) {
            $summary = WordpressMemberAccounts::accountSummary($pendingUser);

            if (is_array($summary)) {
                echo '<p>' . esc_html__('WordPress account to link', 'foreningsplugin') . ': ' . esc_html($summary['name'] . ' (' . $summary['email'] . ')') . '</p>';
                self::linkForm($personId, $pendingUser, __('Link this account', 'foreningsplugin'));
            }
        }

        if ($status->outcome === AccountOutcome::Eligible || $status->outcome === AccountOutcome::Failed) {
            $label = $status->outcome === AccountOutcome::Failed
                ? __('Retry account creation', 'foreningsplugin')
                : __('Create member account now', 'foreningsplugin');
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_create_member_account">';
            echo '<input type="hidden" name="person_id" value="' . esc_attr((string) $personId) . '">';
            wp_nonce_field('assoc_create_member_account');
            submit_button($label);
            echo '</form>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_find_member_account">';
        echo '<input type="hidden" name="person_id" value="' . esc_attr((string) $personId) . '">';
        wp_nonce_field('assoc_find_member_account');
        echo '<p><label>' . esc_html__('WordPress username or email', 'foreningsplugin') . ' ';
        echo '<input class="regular-text" type="text" name="account_login" value="" required>';
        echo '</label></p>';
        submit_button(__('Find account', 'foreningsplugin'), 'secondary');
        echo '</form>';
    }

    private static function linked(MemberAccountStatus $status, int $personId, bool $canEdit): void
    {
        if (is_string($status->accountName) && $status->accountName !== '') {
            echo '<p>' . esc_html__('WordPress user', 'foreningsplugin') . ': ' . esc_html($status->accountName) . '</p>';
        }

        if (is_string($status->accountEmail) && $status->accountEmail !== '') {
            echo '<p>' . esc_html__('Account email', 'foreningsplugin') . ': ' . esc_html($status->accountEmail) . '</p>';
        }

        if ($status->emailMismatch) {
            echo '<p>' . esc_html__('Member email', 'foreningsplugin') . ': ' . esc_html($status->personEmail) . '<br>';
            echo esc_html__('WordPress account email', 'foreningsplugin') . ': ' . esc_html((string) $status->accountEmail) . '</p>';
            echo '<p>' . esc_html__('The member email and the WordPress account email are different. WordPress keeps its own account email.', 'foreningsplugin') . '</p>';
        }

        if (! $canEdit) {
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_unlink_member_account">';
        echo '<input type="hidden" name="person_id" value="' . esc_attr((string) $personId) . '">';
        wp_nonce_field('assoc_unlink_member_account');
        echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__('Unlink this WordPress account from the member? The WordPress user itself will not be deleted.', 'foreningsplugin') . '</label></p>';
        submit_button(__('Unlink account', 'foreningsplugin'), 'delete');
        echo '</form>';
    }

    private static function linkForm(int $personId, int $userId, string $label): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_link_member_account">';
        echo '<input type="hidden" name="person_id" value="' . esc_attr((string) $personId) . '">';
        echo '<input type="hidden" name="wp_user_id" value="' . esc_attr((string) $userId) . '">';
        wp_nonce_field('assoc_link_member_account');
        echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__('Link this WordPress account to the member.', 'foreningsplugin') . '</label></p>';
        submit_button($label);
        echo '</form>';
    }

    private static function label(AccountOutcome $outcome): string
    {
        return match ($outcome) {
            AccountOutcome::AlreadyLinked => __('Linked', 'foreningsplugin'),
            AccountOutcome::KnownMinor => __('Not automatically created', 'foreningsplugin'),
            AccountOutcome::SharedPersonEmail, AccountOutcome::WordpressEmailConflict, AccountOutcome::Failed => __('Needs attention', 'foreningsplugin'),
            default => __('Not created', 'foreningsplugin'),
        };
    }

    private static function reason(AccountOutcome $outcome): string
    {
        return match ($outcome) {
            AccountOutcome::NoEmail => __('No email address', 'foreningsplugin'),
            AccountOutcome::Deceased => __('This person is deceased.', 'foreningsplugin'),
            AccountOutcome::NotActiveMember => __('This person is not an active individual member.', 'foreningsplugin'),
            AccountOutcome::SharedPersonEmail => __('This email address is used by more than one person. Resolve the member email addresses before automatic account creation.', 'foreningsplugin'),
            AccountOutcome::WordpressEmailConflict => __('A WordPress account already uses this email.', 'foreningsplugin'),
            AccountOutcome::Eligible => __('A member account can be created for this person.', 'foreningsplugin'),
            AccountOutcome::Failed => __('The account could not be created.', 'foreningsplugin'),
            default => __('No WordPress account is linked.', 'foreningsplugin'),
        };
    }
}
