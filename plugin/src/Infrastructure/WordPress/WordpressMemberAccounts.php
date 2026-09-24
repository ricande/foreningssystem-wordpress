<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Account\MemberAccountProvisioning;
use Foreningssystem\Application\Account\MemberAccountStatus;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Domain\Membership\AssociationDate;

final class WordpressMemberAccounts
{
    public const HOOK = 'assoc_provision_member_accounts';

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'reconcileToday']);
        add_action('init', [self::class, 'schedule']);
    }

    public static function schedule(): void
    {
        if (wp_next_scheduled(self::HOOK) !== false) {
            return;
        }

        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::HOOK);
    }

    public static function reconcileToday(): void
    {
        self::service()->reconcile(AssociationDate::fromIso(wp_date('Y-m-d')));
    }

    public static function provisionPerson(int $personId): void
    {
        if ($personId < 1) {
            return;
        }

        try {
            self::service()->provision($personId, AssociationDate::fromIso(wp_date('Y-m-d')));
        } catch (\Throwable) {
            // The membership change stays saved. Member detail shows that the account was not linked.
        }
    }

    public static function provisionMembership(int $membershipId): void
    {
        if ($membershipId < 1) {
            return;
        }

        $seen = [];

        foreach ((new WpdbMembershipRepository())->participantsForMembership($membershipId) as $participant) {
            if ($participant->role()->countsAsMember()) {
                $seen[$participant->personId()] = true;
            }
        }

        foreach (array_keys($seen) as $personId) {
            self::provisionPerson((int) $personId);
        }
    }

    public static function findUser(string $query): ?int
    {
        $query = trim($query);

        if ($query === '') {
            return null;
        }

        $accounts = new WpMemberAccountGateway();
        $byLogin = $accounts->findUserIdByLogin($query);

        if ($byLogin !== null) {
            return $byLogin;
        }

        if (is_email($query)) {
            return $accounts->findUserIdByEmail($query);
        }

        return null;
    }

    /**
     * @return array{name: string, email: string}|null
     */
    public static function accountSummary(int $userId): ?array
    {
        $accounts = new WpMemberAccountGateway();

        if (! $accounts->userExists($userId)) {
            return null;
        }

        return [
            'name' => $accounts->displayName($userId),
            'email' => $accounts->email($userId),
        ];
    }

    public static function status(int $personId): MemberAccountStatus
    {
        return self::service()->status($personId, AssociationDate::fromIso(wp_date('Y-m-d')));
    }

    public static function service(): MemberAccountProvisioning
    {
        return new MemberAccountProvisioning(
            new WpdbPersonRepository(),
            new WpdbMembershipRepository(),
            new WpMemberAccountGateway(),
            new class implements Authorizer {
                public function allows(string $capability): bool
                {
                    return current_user_can($capability);
                }
            }
        );
    }
}
