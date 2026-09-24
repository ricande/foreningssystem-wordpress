<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Meeting\MeetingService;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Meeting\MeetingLifecycle;

final class WordpressMeetings
{
    public static function service(): MeetingService
    {
        return new MeetingService(
            new WpdbMeetingTypeRepository(),
            new WpdbMeetingRepository(),
            new MeetingLifecycle(),
            new class implements Authorizer {
                public function allows(string $capability): bool
                {
                    return current_user_can($capability);
                }
            },
            new class implements Transaction {
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
            }
        );
    }
}
