<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Infrastructure\Files\PrivateStorageExposure;
use PHPUnit\Framework\TestCase;

final class PrivateStorageExposureTest extends TestCase
{
    public function test_a_served_test_file_is_exposed(): void
    {
        self::assertSame(PrivateStorageExposure::EXPOSED, PrivateStorageExposure::classify(200, "%PDF-1.4\n%abc123\n", 'abc123'));
    }

    public function test_a_refused_test_file_is_blocked(): void
    {
        foreach ([401, 403, 404, 410] as $status) {
            self::assertSame(PrivateStorageExposure::BLOCKED, PrivateStorageExposure::classify($status, 'Forbidden', 'abc123'));
        }
    }

    public function test_an_unclear_answer_is_unknown(): void
    {
        self::assertSame(PrivateStorageExposure::UNKNOWN, PrivateStorageExposure::classify(null, '', 'abc123'));
        self::assertSame(PrivateStorageExposure::UNKNOWN, PrivateStorageExposure::classify(200, 'A login page', 'abc123'));
        self::assertSame(PrivateStorageExposure::UNKNOWN, PrivateStorageExposure::classify(302, '', 'abc123'));
        self::assertSame(PrivateStorageExposure::UNKNOWN, PrivateStorageExposure::classify(503, 'abc123', 'abc123'));
        self::assertSame(PrivateStorageExposure::UNKNOWN, PrivateStorageExposure::classify(200, 'anything', ''));
    }

    public function test_an_acknowledgement_cannot_hide_an_exposed_directory(): void
    {
        self::assertTrue(PrivateStorageExposure::needsAttention(true, PrivateStorageExposure::EXPOSED, true));
        self::assertTrue(PrivateStorageExposure::needsAttention(true, PrivateStorageExposure::EXPOSED, false));
    }

    public function test_a_blocked_directory_needs_no_acknowledgement(): void
    {
        self::assertFalse(PrivateStorageExposure::needsAttention(true, PrivateStorageExposure::BLOCKED, false));
    }

    public function test_an_unknown_answer_falls_back_to_the_acknowledgement(): void
    {
        self::assertTrue(PrivateStorageExposure::needsAttention(true, PrivateStorageExposure::UNKNOWN, false));
        self::assertFalse(PrivateStorageExposure::needsAttention(true, PrivateStorageExposure::UNKNOWN, true));
    }

    public function test_a_directory_outside_the_web_root_needs_nothing(): void
    {
        self::assertFalse(PrivateStorageExposure::needsAttention(false, PrivateStorageExposure::EXPOSED, false));
    }
}
