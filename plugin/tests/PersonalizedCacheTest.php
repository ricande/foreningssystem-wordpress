<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Infrastructure\WordPress\MemberDocumentsBlock;
use Foreningssystem\Infrastructure\WordPress\PersonalizedOutput;
use Foreningssystem\Tests\Support\WordPressRequest;
use PHPUnit\Framework\TestCase;

final class PersonalizedCacheTest extends TestCase
{
    protected function setUp(): void
    {
        WordPressRequest::reset();
    }

    protected function tearDown(): void
    {
        WordPressRequest::reset();
    }

    public function test_personalized_output_is_marked_uncacheable(): void
    {
        PersonalizedOutput::doNotCache();

        self::assertTrue(WordPressRequest::called('nocache_headers'));
        self::assertTrue(defined('DONOTCACHEPAGE'));
        self::assertTrue(constant('DONOTCACHEPAGE'));
    }

    /**
     * The member document list is per visitor. Rendering it on its own page must mark that
     * page uncacheable before the block looks at anything, which is why the archive call
     * failing without WordPress does not matter here.
     */
    public function test_the_member_documents_block_marks_the_page_uncacheable_before_it_reads_documents(): void
    {
        try {
            MemberDocumentsBlock::render();
        } catch (\Throwable) {
            // The document archive needs WordPress. The cache guard runs before it.
        }

        self::assertTrue(WordPressRequest::called('nocache_headers'));
        self::assertTrue(defined('DONOTCACHEPAGE'));
        self::assertTrue(constant('DONOTCACHEPAGE'));
    }

    public function test_both_personalized_blocks_use_the_same_guard(): void
    {
        $documents = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/MemberDocumentsBlock.php');
        $area = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/MemberAreaBlock.php');

        self::assertStringContainsString('PersonalizedOutput::doNotCache();', $documents);
        self::assertStringContainsString('PersonalizedOutput::doNotCache();', $area);
    }
}
