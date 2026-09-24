<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Infrastructure\Persistence\ActionItemSchemaMigration;
use Foreningssystem\Infrastructure\Persistence\BaselineMigration;
use Foreningssystem\Infrastructure\Persistence\DocumentSchemaMigration;
use Foreningssystem\Infrastructure\Persistence\BoardSchemaMigration;
use Foreningssystem\Infrastructure\Persistence\MeetingRecordSchemaMigration;
use Foreningssystem\Infrastructure\Persistence\MinutesPdfSchemaMigration;
use Foreningssystem\Infrastructure\Persistence\MinutesPublicationSchemaMigration;
use Foreningssystem\Infrastructure\Persistence\MinutesRevisionNumberSchemaMigration;
use Foreningssystem\Infrastructure\Persistence\MinutesSchemaMigration;
use Foreningssystem\Infrastructure\Persistence\SignedCopySchemaMigration;
use Foreningssystem\Infrastructure\Persistence\MeetingRosterSchemaMigration;
use Foreningssystem\Infrastructure\Persistence\MeetingSchemaMigration;
use Foreningssystem\Infrastructure\Persistence\MeetingTemplateSchemaMigration;
use Foreningssystem\Infrastructure\Persistence\MembershipSchemaMigration;
use Foreningssystem\Infrastructure\Persistence\MigrationRunner;

final class WordpressMigrations
{
    public static function runner(): MigrationRunner
    {
        global $wpdb;

        return new MigrationRunner(
            new WordpressSchemaVersionStore(),
            new WordpressMigrationLock(),
            [
                new BaselineMigration(),
                new MembershipSchemaMigration($wpdb->prefix, $wpdb->get_charset_collate()),
                new BoardSchemaMigration($wpdb->prefix, $wpdb->get_charset_collate()),
                new MeetingSchemaMigration($wpdb->prefix, $wpdb->get_charset_collate()),
                new MeetingRosterSchemaMigration($wpdb->prefix, $wpdb->get_charset_collate()),
                new MeetingRecordSchemaMigration($wpdb->prefix, $wpdb->get_charset_collate()),
                new ActionItemSchemaMigration($wpdb->prefix, $wpdb->get_charset_collate()),
                new MinutesSchemaMigration($wpdb->prefix, $wpdb->get_charset_collate()),
                new MinutesPdfSchemaMigration($wpdb->prefix, $wpdb->get_charset_collate()),
                new SignedCopySchemaMigration($wpdb->prefix, $wpdb->get_charset_collate()),
                new MinutesPublicationSchemaMigration($wpdb->prefix, $wpdb->get_charset_collate()),
                new DocumentSchemaMigration($wpdb->prefix, $wpdb->get_charset_collate()),
                new MeetingTemplateSchemaMigration($wpdb->prefix, $wpdb->get_charset_collate()),
                new MinutesRevisionNumberSchemaMigration($wpdb->prefix, $wpdb->get_charset_collate()),
            ]
        );
    }

    public static function migrateIfNeeded(): void
    {
        $runner = self::runner();

        if ($runner->currentVersion() >= $runner->latestVersion()) {
            return;
        }

        $runner->migrate();
    }
}
