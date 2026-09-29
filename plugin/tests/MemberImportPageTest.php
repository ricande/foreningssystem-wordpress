<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\People\MemberRegisterPlan;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Infrastructure\WordPress\MemberCsvVault;
use Foreningssystem\Infrastructure\WordPress\MemberImportPage;
use Foreningssystem\Infrastructure\WordPress\MemberImportScreen;
use Foreningssystem\Tests\Support\AdminHalt;
use Foreningssystem\Tests\Support\AdminRedirect;
use Foreningssystem\Tests\Support\WordPressRequest;
use PHPUnit\Framework\TestCase;

final class MemberImportPageTest extends TestCase
{
    protected function setUp(): void
    {
        WordPressRequest::reset();
    }

    protected function tearDown(): void
    {
        MemberCsvVault::discard();
        WordPressRequest::reset();
    }

    public function test_upload_requires_capability_and_nonce(): void
    {
        try {
            MemberImportPage::upload();
            $this->fail('Upload was allowed without edit_members.');
        } catch (AdminHalt $halt) {
            $this->assertSame(403, $halt->status());
            $this->assertStringNotContainsString('check_admin_referer', implode(' ', WordPressRequest::$calls));
        }

        WordPressRequest::allow(Capabilities::EDIT_MEMBERS);

        try {
            MemberImportPage::upload();
            $this->fail('Upload was allowed without a nonce.');
        } catch (AdminHalt $halt) {
            $this->assertSame(403, $halt->status());
        }
    }

    public function test_confirm_and_cancel_require_a_nonce(): void
    {
        WordPressRequest::allow(Capabilities::EDIT_MEMBERS);

        try {
            MemberImportPage::confirm();
            $this->fail('Confirm was allowed without a nonce.');
        } catch (AdminHalt $halt) {
            $this->assertSame(403, $halt->status());
        }

        try {
            MemberImportPage::cancel();
            $this->fail('Cancel was allowed without a nonce.');
        } catch (AdminHalt $halt) {
            $this->assertSame(403, $halt->status());
        }
    }

    public function test_upload_ignores_the_browser_filename_and_confirm_rejects_a_changed_hash(): void
    {
        WordPressRequest::allow(Capabilities::EDIT_MEMBERS);
        WordPressRequest::acceptNonce('assoc_member_csv_upload');
        $csv = "first_name,last_name,email,membership_number,membership_type,started_on\nAnna,Berg,anna@example.test,M-1,ordinary,2020-01-01\n";
        $path = tempnam(sys_get_temp_dir(), 'csv');
        $this->assertIsString($path);
        file_put_contents($path, $csv);
        WordPressRequest::acceptUpload($path);
        $_FILES['member_spreadsheet'] = [
            'tmp_name' => $path,
            'name' => '../../evil.csv',
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($csv),
        ];

        try {
            MemberImportPage::upload();
            $this->fail('Upload did not redirect.');
        } catch (AdminRedirect $redirect) {
            $this->assertStringContainsString('assoc_csv=columns', $redirect->location());
        }

        $session = MemberCsvVault::current();
        $this->assertNotNull($session);
        $this->assertSame($csv, MemberCsvVault::contents());
        $this->assertDoesNotMatchRegularExpression('/evil/', basename($session['path']));
        $this->assertSame(1, count(glob(dirname($session['path']) . '/*.csv') ?: []));

        WordPressRequest::acceptNonce('assoc_member_csv_confirm');
        $_POST['csv_hash'] = 'not-the-file';

        try {
            MemberImportPage::confirm();
            $this->fail('A changed hash was accepted.');
        } catch (AdminRedirect $redirect) {
            $this->assertStringContainsString('assoc_csv_error=mismatch', $redirect->location());
        }

        $this->assertNotNull(MemberCsvVault::current());
        unlink($path);
    }

    public function test_a_repeated_confirm_after_import_does_not_import_again(): void
    {
        WordPressRequest::allow(Capabilities::EDIT_MEMBERS);
        WordPressRequest::acceptNonce('assoc_member_csv_confirm');
        MemberCsvVault::store("first_name,last_name\nAnna,Berg\n", ',');
        $session = MemberCsvVault::current();
        $this->assertNotNull($session);
        MemberCsvVault::finish($session['hash'], 4, 2, 1, [
            ['line' => 3, 'kind' => 'duplicate', 'code' => 'email_exists'],
        ]);
        $_POST['csv_hash'] = $session['hash'];

        try {
            MemberImportPage::confirm();
            $this->fail('The repeated confirm did not stop.');
        } catch (AdminRedirect $redirect) {
            $this->assertStringContainsString('assoc_csv_error=already', $redirect->location());
        }

        $this->assertNull(MemberCsvVault::current());
        $report = MemberCsvVault::report();
        $this->assertNotNull($report);
        $this->assertSame(4, $report['imported']);
        $this->assertSame(2, $report['duplicates']);
        $this->assertSame(1, $report['failed']);
    }

    public function test_cancel_deletes_the_uploaded_file(): void
    {
        WordPressRequest::allow(Capabilities::EDIT_MEMBERS);
        WordPressRequest::acceptNonce('assoc_member_csv_cancel');
        MemberCsvVault::store("first_name,last_name\nAnna,Berg\n", ';');
        $session = MemberCsvVault::current();
        $this->assertNotNull($session);
        $this->assertFileExists($session['path']);

        try {
            MemberImportPage::cancel();
            $this->fail('Cancel did not redirect.');
        } catch (AdminRedirect $redirect) {
            $this->assertStringNotContainsString('assoc_csv=', $redirect->location());
        }

        $this->assertFileDoesNotExist($session['path']);
        $this->assertNull(MemberCsvVault::current());
    }

    public function test_csv_text_is_escaped_in_the_admin_screen(): void
    {
        $plan = new MemberRegisterPlan(
            1,
            [],
            [['code' => 'ignored_column', 'header' => '<script>alert(1)</script>']],
            [],
            [[
                'line' => 2,
                'first_name' => '<script>alert(1)</script>',
                'last_name' => '=HYPERLINK("http://evil.test")',
                'email' => '',
                'birth_date' => '',
                'person_status' => 'known',
                'membership_number' => 'M-1',
                'membership_type' => 'ordinary',
                'membership_status' => 'active',
                'started_on' => '2020-01-01',
                'ended_on' => '',
            ]]
        );

        ob_start();
        MemberImportScreen::previewMarkup($plan, 'abc');
        $preview = (string) ob_get_clean();
        ob_start();
        MemberImportScreen::columnsForm(['<script>alert(1)</script>'], ',', []);
        $columns = (string) ob_get_clean();
        $_GET['assoc_csv_error'] = '<script>alert(1)</script>';
        ob_start();
        MemberImportScreen::render('choose');
        $choose = (string) ob_get_clean();

        $this->assertStringNotContainsString('<script>', $preview . $columns . $choose);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $preview);
        $this->assertStringContainsString('=HYPERLINK', $preview);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $columns);
        $this->assertStringContainsString('Nothing is saved until you confirm the import.', $preview);
    }

    public function test_too_many_rows_names_the_limit(): void
    {
        $this->assertSame(
            'The file has more than 2000 rows.',
            MemberImportScreen::fileError('too_many_rows')
        );
    }

    public function test_cleanup_does_not_delete_other_files_and_removes_an_expired_upload(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'keep');
        $this->assertIsString($outside);
        file_put_contents($outside, 'keep-me');
        MemberCsvVault::store("first_name,last_name\nAnna,Berg\n", ',');
        $session = MemberCsvVault::current();
        $this->assertNotNull($session);
        $directory = dirname($session['path']);
        $notes = $directory . '/notes.txt';
        file_put_contents($notes, '19900101-1234');
        $staleToken = bin2hex(random_bytes(16));
        $stale = $directory . '/' . $staleToken . '.csv';
        file_put_contents($stale, "personnummer\n19900101-1234\n");
        touch($stale, time() - (2 * HOUR_IN_SECONDS));
        $linkToken = bin2hex(random_bytes(16));
        $link = $directory . '/' . $linkToken . '.csv';
        symlink($outside, $link);

        MemberCsvVault::purgeExpiredUploads();

        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($notes);
        $this->assertFileExists($outside);
        $this->assertFileExists($link);
        $this->assertFileExists($session['path']);

        $stored = get_transient('assoc_member_csv_session_' . get_current_user_id());
        $this->assertIsArray($stored);
        $stored['token'] = $linkToken;
        $stored['path'] = $link;
        set_transient('assoc_member_csv_session_' . get_current_user_id(), $stored, HOUR_IN_SECONDS);
        MemberCsvVault::release();

        $this->assertFileExists($outside);
        $this->assertFileExists($notes);
        $this->assertFileExists($link);
        unlink($link);
        unlink($notes);
        unlink($outside);
    }
}
