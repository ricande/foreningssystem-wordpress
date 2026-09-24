<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Document\DocumentArchive;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\Transaction;

final class WordpressDocuments
{
    public static function archive(): DocumentArchive
    {
        return new DocumentArchive(
            new WpdbDocumentRepository(),
            new WpDocumentFileStore(),
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
