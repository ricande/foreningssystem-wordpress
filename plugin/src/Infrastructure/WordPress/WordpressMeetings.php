<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Meeting\MeetingRecord;
use Foreningssystem\Application\Meeting\MinutesComposer;
use Foreningssystem\Application\Meeting\MinutesDrafts;
use Foreningssystem\Application\Meeting\MinutesPdf;
use Foreningssystem\Application\Meeting\MinutesPublication;
use Foreningssystem\Application\Meeting\MinutesPdfDocument;
use Foreningssystem\Application\Meeting\SignedCopies;
use Foreningssystem\Domain\Meeting\MinutesLifecycle;
use Foreningssystem\Application\Meeting\MeetingService;
use Foreningssystem\Application\Meeting\MeetingWorkspace;
use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Meeting\AgendaOrder;
use Foreningssystem\Domain\Meeting\MeetingLifecycle;
use Foreningssystem\Domain\Meeting\MeetingRoster;

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

    public static function workspace(): MeetingWorkspace
    {
        return new MeetingWorkspace(
            new WpdbMeetingRepository(),
            new WpdbPersonRepository(),
            new WpdbParticipantRepository(),
            new WpdbAgendaRepository(),
            new MeetingRoster(),
            new AgendaOrder(),
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

    public static function record(): MeetingRecord
    {
        return new MeetingRecord(
            new WpdbMeetingRepository(),
            new WpdbAgendaRepository(),
            new WpdbPersonRepository(),
            new WpdbMeetingNoteRepository(),
            new WpdbDecisionRepository(),
            new WpdbActionItemRepository(),
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

    public static function minutes(): MinutesDrafts
    {
        return new MinutesDrafts(
            new WpdbMeetingRepository(),
            new WpdbPersonRepository(),
            new WpdbParticipantRepository(),
            new WpdbAgendaRepository(),
            new WpdbMeetingNoteRepository(),
            new WpdbDecisionRepository(),
            new WpdbActionItemRepository(),
            new WpdbMinutesRepository(),
            new MinutesComposer(),
            new MinutesLifecycle(),
            new AgendaOrder(),
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

    public static function pdf(): MinutesPdf
    {
        return new MinutesPdf(
            new WpdbMinutesRepository(),
            new WpMinutesPdfStore(),
            new MinutesPdfDocument(),
            new class implements Authorizer {
                public function allows(string $capability): bool
                {
                    return current_user_can($capability);
                }
            }
        );
    }

    public static function publication(): MinutesPublication
    {
        return new MinutesPublication(
            new WpdbMeetingRepository(),
            new WpdbMeetingTypeRepository(),
            new WpdbMinutesRepository(),
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

    public static function signedCopies(): SignedCopies
    {
        return new SignedCopies(
            new WpdbMinutesRepository(),
            new WpSignedCopyRepository(),
            new WpSignedFileStore(),
            new WpAuditLog(),
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
