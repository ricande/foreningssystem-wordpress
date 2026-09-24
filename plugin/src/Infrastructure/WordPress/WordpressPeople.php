<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\MemberExchange;
use Foreningssystem\Application\People\PeopleService;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Membership\MembershipLedger;

final class WordpressPeople
{
    public static function service(): PeopleService
    {
        return new PeopleService(
            new WpdbPersonRepository(),
            new WpdbMembershipRepository(),
            new MembershipLedger(),
            new class implements Authorizer {
                public function allows(string $capability): bool
                {
                    return current_user_can($capability);
                }
            },
            self::transaction(),
            WordpressBoard::endOpenAssignments()
        );
    }

    public static function exchange(): MemberExchange
    {
        return new MemberExchange(
            new WpdbPersonRepository(),
            new WpdbMembershipRepository(),
            new MembershipLedger(),
            new class implements Authorizer {
                public function allows(string $capability): bool
                {
                    return current_user_can($capability);
                }
            },
            self::transaction(),
            new WpdbOrganizationRepository()
        );
    }

    public static function companies(): \Foreningssystem\Application\People\CompanyMemberships
    {
        return new \Foreningssystem\Application\People\CompanyMemberships(
            new WpdbOrganizationRepository(),
            new WpdbMembershipRepository(),
            new WpdbPersonRepository(),
            self::authorizer(),
            self::transaction()
        );
    }

    public static function identity(): \Foreningssystem\Application\People\PersonalIdentityService
    {
        return new \Foreningssystem\Application\People\PersonalIdentityService(
            new WpdbPersonalIdentityRepository(),
            new WpdbPersonRepository(),
            self::authorizer(),
            new WpAuditLog()
        );
    }

    public static function guardians(): \Foreningssystem\Application\People\GuardianService
    {
        return new \Foreningssystem\Application\People\GuardianService(
            new WpdbGuardianRepository(),
            new WpdbPersonRepository(),
            self::authorizer(),
            new WpAuditLog()
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
