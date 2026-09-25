<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Application\Settings\BoardRoleDefinitions;
use Foreningssystem\Application\Settings\MeetingTypeDefinitions;

final class WordpressAssociationSettings
{
    public static function boardRoles(): BoardRoleDefinitions
    {
        return new BoardRoleDefinitions(
            new WpdbBoardRoleRepository(),
            new WpdbBoardAssignmentRepository(),
            self::authorizer(),
            self::transaction()
        );
    }

    public static function meetingTypes(): MeetingTypeDefinitions
    {
        return new MeetingTypeDefinitions(
            new WpdbMeetingTypeRepository(),
            new WpdbMeetingRepository(),
            new WpdbMeetingTemplateRepository(),
            self::authorizer(),
            self::transaction()
        );
    }

    private static function authorizer(): Authorizer
    {
        return new class implements Authorizer {
            public function allows(string $capability): bool
            {
                return current_user_can($capability);
            }
        };
    }

    private static function transaction(): Transaction
    {
        return new class implements Transaction {
            public function run(callable $callback): mixed
            {
                global $wpdb;

                $wpdb->query('START TRANSACTION');

                try {
                    $result = $callback();
                    $wpdb->query('COMMIT');

                    return $result;
                } catch (\Throwable $error) {
                    $wpdb->query('ROLLBACK');

                    throw $error;
                }
            }
        };
    }
}
