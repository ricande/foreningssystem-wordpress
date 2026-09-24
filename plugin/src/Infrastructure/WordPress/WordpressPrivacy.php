<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Application\Privacy\EraseOutcome;
use Foreningssystem\Application\Privacy\PersonalDataReport;
use Foreningssystem\Application\Privacy\PrivacyErase;
use Foreningssystem\Application\Privacy\PrivacyExport;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\PersonStatus;

final class WordpressPrivacy
{
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'registerEraser']);
    }

    /**
     * @param array<string, array{exporter_friendly_name: string, callback: callable}> $exporters
     * @return array<string, array{exporter_friendly_name: string, callback: callable}>
     */
    public static function registerExporter(array $exporters): array
    {
        $exporters['foreningsplugin'] = [
            'exporter_friendly_name' => __('Föreningsplugin', 'foreningsplugin'),
            'callback' => [self::class, 'export'],
        ];

        return $exporters;
    }

    /**
     * @param array<string, array{eraser_friendly_name: string, callback: callable}> $erasers
     * @return array<string, array{eraser_friendly_name: string, callback: callable}>
     */
    public static function registerEraser(array $erasers): array
    {
        $erasers['foreningsplugin'] = [
            'eraser_friendly_name' => __('Föreningsplugin', 'foreningsplugin'),
            'callback' => [self::class, 'erase'],
        ];

        return $erasers;
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public static function erase(string $email, int $page = 1): array
    {
        if ($page > 1) {
            return self::response(false, false, []);
        }

        $user = get_user_by('email', $email);
        $linkedUserId = $user instanceof \WP_User ? (int) $user->ID : null;

        try {
            $outcomes = self::eraser()->erase($email, $linkedUserId, get_current_user_id());
        } catch (NotAllowed) {
            return self::response(false, false, [
                __('Du har inte behörighet att avidentifiera personen.', 'foreningsplugin'),
            ]);
        }

        return self::response(self::removed($outcomes), self::retained($outcomes), self::messages($outcomes));
    }

    /**
     * @return array{data: list<array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool}
     */
    public static function export(string $email, int $page = 1): array
    {
        if ($page > 1) {
            return ['data' => [], 'done' => true];
        }

        $user = get_user_by('email', $email);
        $linkedUserId = $user instanceof \WP_User ? (int) $user->ID : null;

        return [
            'data' => self::groups(self::service()->collect($email, $linkedUserId, get_current_user_id())),
            'done' => true,
        ];
    }

    public static function service(): PrivacyExport
    {
        return new PrivacyExport(
            new WpdbPersonRepository(),
            new WpdbMembershipRepository(),
            new WpdbBoardAssignmentRepository(),
            new WpdbBoardRoleRepository(),
            new WpdbParticipantRepository(),
            new WpdbMeetingRepository(),
            new WpAuditLog()
        );
    }

    public static function eraser(): PrivacyErase
    {
        return new PrivacyErase(
            new WpdbPersonRepository(),
            new WpdbMembershipRepository(),
            new WpdbBoardAssignmentRepository(),
            new WpdbParticipantRepository(),
            new WpdbMeetingRepository(),
            new WpdbMinutesRepository(),
            new WpSignedCopyRepository(),
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

    /**
     * @return list<array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}>
     */
    public static function groups(PersonalDataReport $report): array
    {
        $groups = [];

        foreach ($report->people() as $person) {
            $groups[] = self::item('foreningsplugin-person', __('Person', 'foreningsplugin'), 'person-' . $person->id(), [
                [__('Förnamn', 'foreningsplugin'), $person->firstName()],
                [__('Efternamn', 'foreningsplugin'), $person->lastName()],
                [__('E-post', 'foreningsplugin'), $person->email()],
                [__('Status', 'foreningsplugin'), self::personStatus($person->status())],
            ]);

            foreach ($person->memberships() as $membership) {
                $fields = [
                    [__('Medlemsnummer', 'foreningsplugin'), $membership->number()],
                    [__('Typ', 'foreningsplugin'), $membership->type()],
                    [__('Status', 'foreningsplugin'), self::membershipStatus($membership->status())],
                    [__('Start', 'foreningsplugin'), $membership->startedOn()],
                ];

                if ($membership->endedOn() !== null) {
                    $fields[] = [__('Slut', 'foreningsplugin'), $membership->endedOn()];
                }

                $groups[] = self::item('foreningsplugin-membership', __('Medlemskap', 'foreningsplugin'), 'membership-' . $membership->id(), $fields);
            }

            foreach ($person->assignments() as $assignment) {
                $fields = [
                    [__('Uppdrag', 'foreningsplugin'), $assignment->roleName()],
                    [__('Start', 'foreningsplugin'), $assignment->startedOn()],
                    [__('Offentlig kontakt', 'foreningsplugin'), $assignment->publicContact()],
                ];

                if ($assignment->endedOn() !== null) {
                    $fields[] = [__('Slut', 'foreningsplugin'), $assignment->endedOn()];
                }

                $groups[] = self::item('foreningsplugin-board', __('Styrelseuppdrag', 'foreningsplugin'), 'assignment-' . $assignment->id(), $fields);
            }

            foreach ($person->attendance() as $attendance) {
                $groups[] = self::item('foreningsplugin-attendance', __('Närvaro', 'foreningsplugin'), 'attendance-' . $attendance->participantId(), [
                    [__('Möte', 'foreningsplugin'), $attendance->meetingTitle()],
                    [__('Datum', 'foreningsplugin'), $attendance->meetingDate()],
                    [__('Närvaro', 'foreningsplugin'), self::presence($attendance->presence())],
                    [__('Uppgift på mötet', 'foreningsplugin'), self::duty($attendance->duty())],
                ]);
            }

            $groups[] = self::item('foreningsplugin-retained', __('Behållna handlingar', 'foreningsplugin'), 'retained-' . $person->id(), [
                [__('Protokoll', 'foreningsplugin'), __('Protokoll kan innehålla ditt namn och behålls. Protokolltexten ingår inte i exporten.', 'foreningsplugin')],
            ]);
        }

        return $groups;
    }

    /**
     * @param list<array{0: string, 1: string}> $fields
     * @return array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}
     */
    private static function item(string $groupId, string $groupLabel, string $itemId, array $fields): array
    {
        $data = [];

        foreach ($fields as $field) {
            $data[] = ['name' => $field[0], 'value' => $field[1]];
        }

        return [
            'group_id' => $groupId,
            'group_label' => $groupLabel,
            'item_id' => $itemId,
            'data' => $data,
        ];
    }

    /**
     * @param list<EraseOutcome> $outcomes
     */
    private static function removed(array $outcomes): bool
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->identifiersCleared() || $outcome->publicContactCleared()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<EraseOutcome> $outcomes
     */
    private static function retained(array $outcomes): bool
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->membershipRetained() || $outcome->assignmentRetained() || $outcome->minutesNameRetained() || $outcome->signedCopyRetained()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<EraseOutcome> $outcomes
     * @return list<string>
     */
    private static function messages(array $outcomes): array
    {
        $messages = [];

        foreach ($outcomes as $outcome) {
            if ($outcome->identifiersCleared()) {
                $messages[] = __('Kontaktuppgifterna är avidentifierade och kontolänken är borttagen.', 'foreningsplugin');
            }

            if ($outcome->publicContactCleared()) {
                $messages[] = __('Den offentliga kontaktuppgiften på uppdraget är borttagen.', 'foreningsplugin');
            }

            if ($outcome->membershipRetained()) {
                $messages[] = __('Medlemsperioderna behålls.', 'foreningsplugin');
            }

            if ($outcome->assignmentRetained()) {
                $messages[] = __('Uppdragsdatum och roller behålls.', 'foreningsplugin');
            }

            if ($outcome->minutesNameRetained()) {
                $messages[] = __('Namnet i ett låst protokoll behålls.', 'foreningsplugin');
            }

            if ($outcome->signedCopyRetained()) {
                $messages[] = __('Den signerade skanningen behålls.', 'foreningsplugin');
            }
        }

        return $messages;
    }

    /**
     * @param list<string> $messages
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    private static function response(bool $removed, bool $retained, array $messages): array
    {
        return [
            'items_removed' => $removed,
            'items_retained' => $retained,
            'messages' => $messages,
            'done' => true,
        ];
    }

    private static function personStatus(string $status): string
    {
        return match ($status) {
            PersonStatus::Deceased->value => __('Avliden', 'foreningsplugin'),
            default => __('Känd', 'foreningsplugin'),
        };
    }

    private static function membershipStatus(string $status): string
    {
        return match ($status) {
            MembershipStatus::Pending->value => __('Väntande', 'foreningsplugin'),
            MembershipStatus::Dormant->value => __('Vilande', 'foreningsplugin'),
            MembershipStatus::Ended->value => __('Avslutad', 'foreningsplugin'),
            default => __('Aktiv', 'foreningsplugin'),
        };
    }

    private static function presence(string $presence): string
    {
        return match ($presence) {
            Presence::Absent->value => __('Frånvarande', 'foreningsplugin'),
            Presence::CoOpted->value => __('Adjungerad', 'foreningsplugin'),
            default => __('Närvarande', 'foreningsplugin'),
        };
    }

    private static function duty(string $duty): string
    {
        return match ($duty) {
            MeetingDuty::Chair->value => __('Ordförande', 'foreningsplugin'),
            MeetingDuty::Adjuster->value => __('Justerare', 'foreningsplugin'),
            default => __('Ingen', 'foreningsplugin'),
        };
    }
}
