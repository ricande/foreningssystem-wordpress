<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\MemberCsvColumns;
use Foreningssystem\Application\People\MemberCsvException;
use Foreningssystem\Application\People\MemberCsvParser;
use Foreningssystem\Application\People\MemberRegisterIssue;
use Foreningssystem\Application\People\MemberRegisterPlan;
use Foreningssystem\Domain\Membership\AssociationDate;

final class MemberImportScreen
{
    public static function render(string $step): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Import members', 'foreningsplugin') . '</h1>';
        self::error();

        if ($step === 'columns') {
            self::columns();
        } elseif ($step === 'preview') {
            self::preview();
        } elseif ($step === 'done') {
            self::done();
        } else {
            self::choose();
        }

        echo '</div>';
    }

    /**
     * @param list<string> $headers
     * @param array<int, string> $selected
     */
    public static function columnsForm(array $headers, string $delimiter, array $selected): void
    {
        echo '<h2>' . esc_html__('Check the columns', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('Match each column to a member field. A column can be left out. Nothing is saved on this step.', 'foreningsplugin') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_member_csv_columns">';
        wp_nonce_field('assoc_member_csv_columns');
        echo '<fieldset><legend>' . esc_html__('Delimiter', 'foreningsplugin') . '</legend>';
        echo '<label><input type="radio" name="delimiter" value=","' . ($delimiter === ',' ? ' checked' : '') . '> ' . esc_html__('Comma', 'foreningsplugin') . '</label> ';
        echo '<label><input type="radio" name="delimiter" value=";"' . ($delimiter !== ',' ? ' checked' : '') . '> ' . esc_html__('Semicolon', 'foreningsplugin') . '</label>';
        echo '</fieldset>';
        echo '<table class="form-table"><thead><tr><th>' . esc_html__('CSV column', 'foreningsplugin') . '</th><th>' . esc_html__('Member field', 'foreningsplugin') . '</th></tr></thead><tbody>';

        foreach ($headers as $index => $header) {
            $current = $selected[$index] ?? MemberCsvColumns::inspect($header)['field'];
            echo '<tr><th>' . esc_html($header) . '</th><td><select name="column[' . esc_attr((string) $index) . ']">';

            foreach (self::fieldOptions() as $value => $label) {
                echo '<option value="' . esc_attr($value) . '"' . ($current === $value ? ' selected' : '') . '>' . esc_html($label) . '</option>';
            }

            echo '</select></td></tr>';
        }

        echo '</tbody></table>';
        submit_button(__('Preview the import', 'foreningsplugin'));
        echo '</form>';
        self::cancelForm();
    }

    public static function previewMarkup(MemberRegisterPlan $plan, string $hash): void
    {
        echo '<h2>' . esc_html__('Check the problems', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('Nothing is saved until you confirm the import.', 'foreningsplugin') . '</p>';
        echo '<ul>';
        echo '<li>' . esc_html(sprintf(__('Rows read: %d', 'foreningsplugin'), $plan->read)) . '</li>';
        echo '<li>' . esc_html(sprintf(__('Ready to import: %d', 'foreningsplugin'), $plan->ready())) . '</li>';
        echo '<li>' . esc_html(sprintf(__('Possible duplicates: %d', 'foreningsplugin'), $plan->duplicates())) . '</li>';
        echo '<li>' . esc_html(sprintf(__('Errors: %d', 'foreningsplugin'), $plan->errors())) . '</li>';
        echo '</ul>';

        if ($plan->notices !== []) {
            echo '<h3>' . esc_html__('Warnings', 'foreningsplugin') . '</h3><ul>';

            foreach ($plan->notices as $notice) {
                echo '<li>' . esc_html(self::notice($notice['code'], $notice['header'])) . '</li>';
            }

            echo '</ul>';
        }

        foreach ($plan->blockers as $blocker) {
            echo '<div class="notice notice-error"><p>' . esc_html(self::blocker($blocker)) . '</p></div>';
        }

        if ($plan->issues !== []) {
            echo '<h3>' . esc_html__('Check the problems', 'foreningsplugin') . '</h3><ul>';
            $shown = 0;

            foreach ($plan->issues as $issue) {
                if ($shown >= 100) {
                    echo '<li>' . esc_html__('Showing the first 100 problems.', 'foreningsplugin') . '</li>';
                    break;
                }

                echo '<li>' . esc_html(self::issue($issue)) . '</li>';
                $shown++;
            }

            echo '</ul>';
        }

        if ($plan->readyRows !== []) {
            echo '<h3>' . esc_html__('Preview the import', 'foreningsplugin') . '</h3>';
            echo '<table class="widefat"><thead><tr>';
            echo '<th>' . esc_html__('Row', 'foreningsplugin') . '</th>';
            echo '<th>' . esc_html__('Name', 'foreningsplugin') . '</th>';
            echo '<th>' . esc_html__('Membership number', 'foreningsplugin') . '</th>';
            echo '<th>' . esc_html__('Membership type', 'foreningsplugin') . '</th>';
            echo '<th>' . esc_html__('Membership status', 'foreningsplugin') . '</th>';
            echo '<th>' . esc_html__('Start date', 'foreningsplugin') . '</th>';
            echo '</tr></thead><tbody>';
            $shown = 0;

            foreach ($plan->readyRows as $row) {
                if ($shown >= 30) {
                    break;
                }

                echo '<tr>';
                echo '<td>' . esc_html((string) $row['line']) . '</td>';
                echo '<td>' . esc_html($row['first_name'] . ' ' . $row['last_name']) . '</td>';
                echo '<td>' . esc_html($row['membership_number']) . '</td>';
                echo '<td>' . esc_html(self::label($row['membership_type'])) . '</td>';
                echo '<td>' . esc_html(self::label($row['membership_status'])) . '</td>';
                echo '<td>' . esc_html($row['started_on']) . '</td>';
                echo '</tr>';
                $shown++;
            }

            echo '</tbody></table>';

            if (count($plan->readyRows) > 30) {
                echo '<p>' . esc_html__('Showing the first 30 ready rows.', 'foreningsplugin') . '</p>';
            }
        }

        if ($plan->ready() > 0 && $plan->blockers === []) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_member_csv_confirm">';
            wp_nonce_field('assoc_member_csv_confirm');
            echo '<input type="hidden" name="csv_hash" value="' . esc_attr($hash) . '">';
            submit_button(sprintf(__('Import %d members', 'foreningsplugin'), $plan->ready()));
            echo '</form>';
        } else {
            echo '<p>' . esc_html__('No rows were ready to import.', 'foreningsplugin') . '</p>';
        }

        echo '<p><a href="' . esc_url(self::url('columns')) . '">' . esc_html__('Check the columns', 'foreningsplugin') . '</a></p>';
        self::cancelForm();
    }

    private static function choose(): void
    {
        echo '<h2>' . esc_html__('Choose a CSV file', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('Bring in an existing member list. The file is checked before anything is saved.', 'foreningsplugin') . '</p>';
        echo '<p>' . esc_html__('The file may use commas or semicolons. Swedish characters are kept.', 'foreningsplugin') . '</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_member_csv_upload">';
        wp_nonce_field('assoc_member_csv_upload');
        echo '<p><input type="file" name="member_spreadsheet" accept=".csv,text/csv" required></p>';
        submit_button(__('Upload and continue', 'foreningsplugin'));
        echo '</form>';
        echo '<p><a href="' . esc_url(self::membersUrl()) . '">' . esc_html__('Back to members', 'foreningsplugin') . '</a></p>';
    }

    private static function columns(): void
    {
        $bytes = MemberCsvVault::contents();
        $session = MemberCsvVault::current();

        if ($bytes === null || $session === null) {
            self::missing();

            return;
        }

        try {
            $table = (new MemberCsvParser())->parse($bytes, $session['delimiter']);
        } catch (MemberCsvException $error) {
            echo '<div class="notice notice-error"><p>' . esc_html(self::fileError($error->reason())) . '</p></div>';
            self::cancelForm();

            return;
        }

        self::columnsForm($table->headers, $table->delimiter, $session['mapping']);
    }

    private static function preview(): void
    {
        $bytes = MemberCsvVault::contents();
        $session = MemberCsvVault::current();

        if ($bytes === null || $session === null) {
            self::missing();

            return;
        }

        try {
            $table = (new MemberCsvParser())->parse($bytes, $session['delimiter']);
            $plan = WordpressPeople::registerImport()->plan(
                $table,
                $session['mapping'],
                AssociationDate::fromIso(wp_date('Y-m-d'))
            );
        } catch (MemberCsvException $error) {
            echo '<div class="notice notice-error"><p>' . esc_html(self::fileError($error->reason())) . '</p></div>';
            self::cancelForm();

            return;
        }

        self::previewMarkup($plan, $session['hash']);
    }

    private static function done(): void
    {
        $report = MemberCsvVault::report();
        echo '<h2>' . esc_html__('Import finished', 'foreningsplugin') . '</h2>';

        if ($report === null) {
            self::missing();

            return;
        }

        echo '<ul>';
        echo '<li>' . esc_html(sprintf(__('Imported: %d', 'foreningsplugin'), $report['imported'])) . '</li>';
        echo '<li>' . esc_html(sprintf(__('Skipped: %d', 'foreningsplugin'), $report['duplicates'])) . '</li>';
        echo '<li>' . esc_html(sprintf(__('Failed: %d', 'foreningsplugin'), $report['failed'])) . '</li>';
        echo '</ul>';

        if ($report['issues'] !== []) {
            echo '<ul>';

            foreach ($report['issues'] as $issue) {
                if ($issue['kind'] !== 'duplicate' && $issue['kind'] !== 'error') {
                    continue;
                }

                echo '<li>' . esc_html(self::issue(new MemberRegisterIssue($issue['line'], $issue['kind'], $issue['code']))) . '</li>';
            }

            echo '</ul>';
        }

        echo '<p><a class="button" href="' . esc_url(self::url('choose')) . '">' . esc_html__('Start over', 'foreningsplugin') . '</a> ';
        echo '<a href="' . esc_url(self::membersUrl()) . '">' . esc_html__('Back to members', 'foreningsplugin') . '</a></p>';
    }

    private static function missing(): void
    {
        echo '<p>' . esc_html__('The uploaded file is no longer available. Choose the file again.', 'foreningsplugin') . '</p>';
        echo '<p><a href="' . esc_url(self::url('choose')) . '">' . esc_html__('Choose a CSV file', 'foreningsplugin') . '</a></p>';
    }

    private static function error(): void
    {
        $code = isset($_GET['assoc_csv_error']) ? sanitize_key((string) $_GET['assoc_csv_error']) : '';

        if ($code === '') {
            return;
        }

        $message = self::fileError($code);

        if ($message === '') {
            return;
        }

        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }

    private static function cancelForm(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_member_csv_cancel">';
        wp_nonce_field('assoc_member_csv_cancel');
        submit_button(__('Cancel import', 'foreningsplugin'), 'secondary');
        echo '</form>';
    }

    /**
     * @return array<string, string>
     */
    private static function fieldOptions(): array
    {
        return [
            '' => __('Do not import', 'foreningsplugin'),
            'first_name' => __('First name', 'foreningsplugin'),
            'last_name' => __('Last name', 'foreningsplugin'),
            'email' => __('Email', 'foreningsplugin'),
            'birth_date' => __('Birth date', 'foreningsplugin'),
            'person_status' => __('Person status', 'foreningsplugin'),
            'membership_number' => __('Membership number', 'foreningsplugin'),
            'membership_type' => __('Membership type', 'foreningsplugin'),
            'membership_status' => __('Membership status', 'foreningsplugin'),
            'started_on' => __('Start date', 'foreningsplugin'),
            'ended_on' => __('End date', 'foreningsplugin'),
        ];
    }

    private static function issue(MemberRegisterIssue $issue): string
    {
        $text = match ($issue->code) {
            'missing_name' => __('Row %d: A person needs a first and last name.', 'foreningsplugin'),
            'invalid_email' => __('Row %d: Email is not valid.', 'foreningsplugin'),
            'invalid_date' => __('Row %d: Date must use YYYY-MM-DD or DD.MM.YYYY.', 'foreningsplugin'),
            'ended_needs_date' => __('Row %d: An ended membership needs an end date.', 'foreningsplugin'),
            'open_has_end' => __('Row %d: An open membership has no end date.', 'foreningsplugin'),
            'deceased_open' => __('Row %d: A deceased person cannot have an open membership.', 'foreningsplugin'),
            'unknown_type' => __('Row %d: Unknown membership type.', 'foreningsplugin'),
            'company' => __('Row %d: Company memberships use the structured member file.', 'foreningsplugin'),
            'unknown_status' => __('Row %d: Unknown person or membership status.', 'foreningsplugin'),
            'youth_birth' => __('Row %d: A youth membership needs a birth date.', 'foreningsplugin'),
            'future_birth' => __('Row %d: Birth date cannot be in the future.', 'foreningsplugin'),
            'ends_before_start' => __('Row %d: A membership cannot end before it starts.', 'foreningsplugin'),
            'value_too_long' => __('Row %d: A value is too long.', 'foreningsplugin'),
            'needs_number_and_type' => __('Row %d: A membership needs a number and a type.', 'foreningsplugin'),
            'number_exists' => __('Row %d: The membership number is already in the register.', 'foreningsplugin'),
            'number_repeated' => __('Row %d: The membership number is repeated in this file.', 'foreningsplugin'),
            'email_exists' => __('Row %d: This email already belongs to a person, so the row was not imported.', 'foreningsplugin'),
            'email_repeated' => __('Row %d: This email is repeated in this file, so the row was not imported.', 'foreningsplugin'),
            default => __('Row %d: The row could not be saved.', 'foreningsplugin'),
        };

        return sprintf($text, $issue->line);
    }

    private static function notice(string $code, string $header): string
    {
        return match ($code) {
            'ignored_identity' => __('Personal identity numbers are not imported. That column is ignored.', 'foreningsplugin'),
            'ignored_column' => str_replace('%s', $header, __('The column "%s" has no member field and will not be imported.', 'foreningsplugin')),
            'status_defaults_active' => __('Empty membership status is imported as active.', 'foreningsplugin'),
            'person_defaults_known' => __('Empty person status is imported as known.', 'foreningsplugin'),
            default => '',
        };
    }

    private static function blocker(string $code): string
    {
        return match ($code) {
            'map_first_name' => __('Map a column to first name.', 'foreningsplugin'),
            'map_last_name' => __('Map a column to last name.', 'foreningsplugin'),
            'map_membership_number' => __('Map a column to membership number.', 'foreningsplugin'),
            'map_membership_type' => __('Map a column to membership type.', 'foreningsplugin'),
            'map_started_on' => __('Map a column to start date.', 'foreningsplugin'),
            default => sprintf(__('%s is mapped more than once.', 'foreningsplugin'), self::fieldOptions()[substr($code, 6)] ?? ''),
        };
    }

    public static function fileError(string $code): string
    {
        return match ($code) {
            'too_large' => __('The file is too large.', 'foreningsplugin'),
            'too_many_rows' => sprintf(
                /* translators: %d: maximum number of data rows in one member spreadsheet. */
                __('The file has more than %d rows.', 'foreningsplugin'),
                MemberCsvParser::MAX_ROWS
            ),
            'failed' => __('The import could not be completed.', 'foreningsplugin'),
            'too_many_columns' => __('The file has too many columns.', 'foreningsplugin'),
            'not_utf8' => __('The file is not UTF-8.', 'foreningsplugin'),
            'unreadable' => __('The file could not be read as CSV.', 'foreningsplugin'),
            'bad_delimiter' => __('Choose a comma or a semicolon as the delimiter.', 'foreningsplugin'),
            'missing' => __('The uploaded file is no longer available. Choose the file again.', 'foreningsplugin'),
            'already' => __('This file was already imported. Nothing was saved again.', 'foreningsplugin'),
            'mismatch' => __('The uploaded file is no longer available. Choose the file again.', 'foreningsplugin'),
            default => '',
        };
    }

    private static function label(string $slug): string
    {
        return match ($slug) {
            'ordinary' => __('Ordinary', 'foreningsplugin'),
            'youth' => __('Youth', 'foreningsplugin'),
            'family' => __('Family', 'foreningsplugin'),
            'active' => __('Active', 'foreningsplugin'),
            'pending' => __('Pending', 'foreningsplugin'),
            'dormant' => __('Dormant', 'foreningsplugin'),
            'ended' => __('Ended', 'foreningsplugin'),
            'known' => __('Known', 'foreningsplugin'),
            'deceased' => __('Deceased', 'foreningsplugin'),
            default => $slug,
        };
    }

    private static function url(string $step): string
    {
        return add_query_arg([
            'page' => 'foreningsplugin-members',
            'assoc_csv' => $step,
        ], admin_url('admin.php'));
    }

    private static function membersUrl(): string
    {
        return add_query_arg(['page' => 'foreningsplugin-members'], admin_url('admin.php'));
    }
}
