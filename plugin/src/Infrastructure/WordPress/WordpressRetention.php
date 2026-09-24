<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Application\Privacy\Retention;
use Foreningssystem\Application\Privacy\RetentionResult;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Privacy\RetentionPeriod;
use InvalidArgumentException;

final class WordpressRetention
{
    public const OPTION = 'assoc_retention_years';

    public const HOOK = 'assoc_apply_retention';

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'applyToday']);
        add_action('init', [self::class, 'schedule']);
    }

    public static function schedule(): void
    {
        if (wp_next_scheduled(self::HOOK) !== false) {
            return;
        }

        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::HOOK);
    }

    public static function load(): RetentionPeriod
    {
        $stored = get_option(self::OPTION, null);

        if (is_numeric($stored)) {
            try {
                return new RetentionPeriod((int) $stored);
            } catch (InvalidArgumentException) {
                $period = RetentionPeriod::default();
                update_option(self::OPTION, $period->years());

                return $period;
            }
        }

        $period = RetentionPeriod::default();
        update_option(self::OPTION, $period->years());

        return $period;
    }

    public static function save(RetentionPeriod $period): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            throw new NotAllowed(Capabilities::MANAGE_ASSOCIATION);
        }

        update_option(self::OPTION, $period->years());
    }

    public static function applyToday(): RetentionResult
    {
        return self::service()->apply(
            AssociationDate::fromIso(wp_date('Y-m-d')),
            self::load(),
            get_current_user_id()
        );
    }

    public static function service(): Retention
    {
        return new Retention(
            new WpdbPersonRepository(),
            new WpdbMembershipRepository(),
            new WpdbBoardAssignmentRepository(),
            new WpAuditLog(),
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
            },
            new WpdbPersonalIdentityRepository()
        );
    }
}
