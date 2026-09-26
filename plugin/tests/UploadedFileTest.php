<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Document\DocumentFileType;
use Foreningssystem\Domain\Meeting\SignedCopyType;
use Foreningssystem\Infrastructure\WordPress\DocumentsPage;
use Foreningssystem\Infrastructure\WordPress\MeetingDetailPage;
use Foreningssystem\Infrastructure\WordPress\UploadedFile;
use Foreningssystem\Tests\Support\AdminRedirect;
use Foreningssystem\Tests\Support\WordPressRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UploadedFileTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        WordPressRequest::reset();
    }

    protected function tearDown(): void
    {
        WordPressRequest::reset();

        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->paths = [];
    }

    public function test_the_upload_limit_is_the_limit_the_domain_file_types_accept(): void
    {
        $tooLarge = str_pad('%PDF-1.4', UploadedFile::MAX_BYTES + 1, ' ');
        $largest = str_pad('%PDF-1.4', UploadedFile::MAX_BYTES, ' ');

        self::assertSame(DocumentFileType::PDF, DocumentFileType::fromBytes($largest));
        self::assertSame(SignedCopyType::PDF, SignedCopyType::fromBytes($largest));

        try {
            DocumentFileType::fromBytes($tooLarge);
            self::fail('The document type accepted more than the upload limit.');
        } catch (InvalidArgumentException) {
            // The route and the domain stop at the same size.
        }

        try {
            SignedCopyType::fromBytes($tooLarge);
            self::fail('The signed copy type accepted more than the upload limit.');
        } catch (InvalidArgumentException) {
            // The route and the domain stop at the same size.
        }
    }

    public function test_a_file_within_the_limit_is_read(): void
    {
        $this->upload('document_file', '%PDF-1.4 signed', UPLOAD_ERR_OK);

        self::assertSame('%PDF-1.4 signed', UploadedFile::bytes('document_file', 'nope'));
    }

    public function test_a_file_larger_than_the_limit_is_rejected_without_being_read(): void
    {
        $path = $this->sparse('signed_copy', UploadedFile::MAX_BYTES + 1);
        $before = memory_get_peak_usage();

        $this->reject('signed_copy');

        self::assertLessThan(UploadedFile::MAX_BYTES, memory_get_peak_usage() - $before);
        self::assertSame(UploadedFile::MAX_BYTES + 1, filesize($path));
    }

    public function test_a_declared_size_over_the_limit_is_rejected_even_when_the_file_is_small(): void
    {
        $this->upload('signed_copy', '%PDF-1.4', UPLOAD_ERR_OK);
        $_FILES['signed_copy']['size'] = UploadedFile::MAX_BYTES + 1;

        $this->reject('signed_copy');
    }

    public function test_a_small_declared_size_does_not_hide_a_large_file(): void
    {
        $this->sparse('signed_copy', UploadedFile::MAX_BYTES + 1);
        $_FILES['signed_copy']['size'] = 12;

        $this->reject('signed_copy');
    }

    public function test_an_empty_file_is_rejected(): void
    {
        $this->upload('document_file', '', UPLOAD_ERR_OK);

        $this->reject('document_file');
    }

    public function test_a_php_upload_error_is_rejected(): void
    {
        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_NO_TMP_DIR] as $error) {
            $this->upload('document_file', '%PDF-1.4', $error);

            $this->reject('document_file');
        }
    }

    public function test_a_path_that_is_not_an_upload_is_rejected(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'assoc-not-upload-');
        $this->paths[] = $path;
        file_put_contents($path, '%PDF-1.4');
        $_FILES['document_file'] = ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 8];

        $this->reject('document_file');
    }

    public function test_a_missing_upload_slot_is_rejected(): void
    {
        $this->reject('document_file');
    }

    public function test_the_limit_can_be_lowered_and_is_inclusive(): void
    {
        $this->upload('document_file', '0123456789abcdef', UPLOAD_ERR_OK);

        self::assertSame('0123456789abcdef', UploadedFile::bytes('document_file', 'nope', 16));

        try {
            UploadedFile::bytes('document_file', 'nope', 15);
            self::fail('A file one byte over the limit was accepted.');
        } catch (InvalidArgumentException) {
            // The limit is inclusive.
        }
    }

    public function test_an_oversized_signed_copy_stops_at_the_meeting_error_notice(): void
    {
        WordPressRequest::allow(Capabilities::FINALIZE_MINUTES, Capabilities::MANAGE_DOCUMENTS);
        WordPressRequest::acceptNonce('assoc_upload_signed_copy');
        $_POST = ['meeting_id' => '12', 'revision_id' => '34'];
        $this->sparse('signed_copy', UploadedFile::MAX_BYTES + 1);

        try {
            MeetingDetailPage::uploadSignedCopy();
            self::fail('The oversized signed copy was accepted.');
        } catch (AdminRedirect $redirect) {
            self::assertStringContainsString('assoc_notice=signed_type', $redirect->location());
            self::assertStringContainsString('meeting=12', $redirect->location());
        }
    }

    public function test_an_oversized_document_stops_at_the_document_error_notice(): void
    {
        WordPressRequest::allow(Capabilities::MANAGE_DOCUMENTS);
        WordPressRequest::acceptNonce('assoc_add_document');
        $_POST = ['title' => 'Stadgar', 'visibility' => 'member'];
        $this->sparse('document_file', UploadedFile::MAX_BYTES + 1);

        try {
            DocumentsPage::add();
            self::fail('The oversized document was accepted.');
        } catch (AdminRedirect $redirect) {
            self::assertStringContainsString('assoc_notice=document_invalid', $redirect->location());
        }
    }

    public function test_a_failed_document_upload_stops_at_the_document_error_notice(): void
    {
        WordPressRequest::allow(Capabilities::MANAGE_DOCUMENTS);
        WordPressRequest::acceptNonce('assoc_add_document');
        $_POST = ['title' => 'Stadgar', 'visibility' => 'member'];
        $this->upload('document_file', '%PDF-1.4', UPLOAD_ERR_INI_SIZE);

        try {
            DocumentsPage::add();
            self::fail('A failed upload was accepted.');
        } catch (AdminRedirect $redirect) {
            self::assertStringContainsString('assoc_notice=document_invalid', $redirect->location());
        }
    }

    private function reject(string $key): void
    {
        try {
            UploadedFile::bytes($key, 'The upload was refused.');
            self::fail('The upload in ' . $key . ' was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('The upload was refused.', $error->getMessage());
        }
    }

    private function upload(string $key, string $contents, int $error): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'assoc-upload-');
        $this->paths[] = $path;
        file_put_contents($path, $contents);
        WordPressRequest::acceptUpload($path);
        $_FILES[$key] = ['tmp_name' => $path, 'error' => $error, 'size' => strlen($contents)];

        return $path;
    }

    /**
     * A sparse file: the size is real, the disk cost is not.
     */
    private function sparse(string $key, int $size): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'assoc-upload-');
        $this->paths[] = $path;
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            self::fail('The test upload could not be created.');
        }

        fseek($handle, $size - 1);
        fwrite($handle, "\0");
        fclose($handle);
        clearstatcache(true, $path);
        WordPressRequest::acceptUpload($path);
        $_FILES[$key] = ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => $size];

        return $path;
    }
}
