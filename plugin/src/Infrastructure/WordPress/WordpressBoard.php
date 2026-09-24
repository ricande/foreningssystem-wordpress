<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Board\BoardService;
use Foreningssystem\Application\Board\EndOpenBoardAssignments;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Board\BoardAssignmentLedger;

final class WordpressBoard
{
    public static function service(): BoardService
    {
        return new BoardService(
            new WpdbPersonRepository(),
            new WpdbMembershipRepository(),
            new WpdbBoardRoleRepository(),
            new WpdbBoardAssignmentRepository(),
            new BoardAssignmentLedger(),
            self::authorizer(),
            self::transaction()
        );
    }

    public static function endOpenAssignments(): EndOpenBoardAssignments
    {
        return new EndOpenBoardAssignments(new WpdbBoardAssignmentRepository(), new BoardAssignmentLedger());
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
