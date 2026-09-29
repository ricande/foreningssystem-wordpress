<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\MemberCsvException;
use Foreningssystem\Application\People\MemberCsvParser;
use Foreningssystem\Application\People\MemberCsvColumns;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\AssociationDate;
use InvalidArgumentException;

final class MemberImportPage
{
    public static function upload(): void
    {
        self::guard('assoc_member_csv_upload');

        $file = $_FILES['member_spreadsheet'] ?? null;

        if (is_array($file) && isset($file['size']) && is_numeric($file['size']) && (int) $file['size'] > MemberCsvParser::MAX_BYTES) {
            self::redirect('choose', 'too_large');
        }

        try {
            $csv = UploadedFile::bytes('member_spreadsheet', 'unreadable', MemberCsvParser::MAX_BYTES);
            $parser = new MemberCsvParser();
            $delimiter = $parser->detectDelimiter($csv);
            $parser->parse($csv, $delimiter);
            MemberCsvVault::store($csv, $delimiter);
        } catch (MemberCsvException $error) {
            self::redirect('choose', $error->reason());
        } catch (InvalidArgumentException) {
            self::redirect('choose', 'unreadable');
        }

        self::redirect('columns');
    }

    public static function saveColumns(): void
    {
        self::guard('assoc_member_csv_columns');
        $rawDelimiter = isset($_POST['delimiter']) ? wp_unslash($_POST['delimiter']) : ',';
        $delimiter = $rawDelimiter === ';' ? ';' : ',';
        $posted = isset($_POST['column']) ? wp_unslash($_POST['column']) : [];
        $mapping = [];

        if (is_array($posted)) {
            foreach ($posted as $index => $field) {
                if (! is_scalar($index) || ! is_scalar($field)) {
                    continue;
                }

                $name = sanitize_key((string) $field);

                if ($name !== '' && ! MemberCsvColumns::knownField($name)) {
                    continue;
                }

                $mapping[(int) $index] = $name;
            }
        }

        if (! MemberCsvVault::saveMapping($delimiter, $mapping)) {
            self::redirect('choose', 'missing');
        }

        self::redirect('preview');
    }

    public static function confirm(): void
    {
        self::guard('assoc_member_csv_confirm');
        $postedHash = isset($_POST['csv_hash']) && is_string($_POST['csv_hash'])
            ? sanitize_text_field(wp_unslash($_POST['csv_hash']))
            : '';
        $session = MemberCsvVault::current();
        $bytes = MemberCsvVault::contents();

        if ($session === null || $bytes === null) {
            $report = MemberCsvVault::report();

            if ($report !== null && $postedHash !== '' && hash_equals($report['hash'], $postedHash)) {
                self::redirect('done', 'already');
            }

            self::redirect('choose', 'missing');
        }

        if (! hash_equals($session['hash'], $postedHash)) {
            self::redirect('choose', 'mismatch');
        }

        try {
            $table = (new MemberCsvParser())->parse($bytes, $session['delimiter']);
            $result = WordpressPeople::registerImport()->commit(
                $table,
                $session['mapping'],
                AssociationDate::fromIso(wp_date('Y-m-d'))
            );
        } catch (MemberCsvException $error) {
            MemberCsvVault::release();
            self::redirect('choose', $error->reason());
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to change members.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (\Throwable) {
            MemberCsvVault::release();
            self::redirect('choose', 'failed');
        }

        $issues = [];

        foreach ($result->issues as $issue) {
            $issues[] = [
                'line' => $issue->line,
                'kind' => $issue->kind,
                'code' => $issue->code,
            ];
        }

        MemberCsvVault::finish($session['hash'], $result->imported, $result->duplicates, $result->failed, $issues);
        self::redirect('done');
    }

    public static function cancel(): void
    {
        self::guard('assoc_member_csv_cancel');
        MemberCsvVault::discard();
        wp_safe_redirect(add_query_arg(['page' => 'foreningsplugin-members'], admin_url('admin.php')));
        exit;
    }

    private static function guard(string $nonce): void
    {
        if (! current_user_can(Capabilities::EDIT_MEMBERS)) {
            wp_die(esc_html__('You do not have permission to change members.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    private static function redirect(string $step, string $error = ''): never
    {
        $args = [
            'page' => 'foreningsplugin-members',
            'assoc_csv' => $step,
        ];

        if ($error !== '') {
            $args['assoc_csv_error'] = $error;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
