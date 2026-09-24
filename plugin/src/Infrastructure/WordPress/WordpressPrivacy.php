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
            'exporter_friendly_name' => __('Association plugin', 'foreningsplugin'),
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
            'eraser_friendly_name' => __('Association plugin', 'foreningsplugin'),
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
                __('You do not have permission to anonymize the person.', 'foreningsplugin'),
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
                [__('First name', 'foreningsplugin'), $person->firstName()],
                [__('Last name', 'foreningsplugin'), $person->lastName()],
                [__('Email', 'foreningsplugin'), $person->email()],
                [__('Status', 'foreningsplugin'), self::personStatus($person->status())],
            ]);

            foreach ($person->memberships() as $membership) {
                $fields = [
                    [__('Membership number', 'foreningsplugin'), $membership->number()],
                    [__('Type', 'foreningsplugin'), $membership->type()],
                    [__('Status', 'foreningsplugin'), self::membershipStatus($membership->status())],
                    [__('Start', 'foreningsplugin'), $membership->startedOn()],
                ];

                if ($membership->endedOn() !== null) {
                    $fields[] = [__('End', 'foreningsplugin'), $membership->endedOn()];
                }

                $groups[] = self::item('foreningsplugin-membership', __('Membership', 'foreningsplugin'), 'membership-' . $membership->id(), $fields);
            }

            foreach ($person->assignments() as $assignment) {
                $fields = [
                    [__('Assignment', 'foreningsplugin'), $assignment->roleName()],
                    [__('Start', 'foreningsplugin'), $assignment->startedOn()],
                    [__('Public contact', 'foreningsplugin'), $assignment->publicContact()],
                ];

                if ($assignment->endedOn() !== null) {
                    $fields[] = [__('End', 'foreningsplugin'), $assignment->endedOn()];
                }

                $groups[] = self::item('foreningsplugin-board', __('Board assignment', 'foreningsplugin'), 'assignment-' . $assignment->id(), $fields);
            }

            foreach ($person->attendance() as $attendance) {
                $groups[] = self::item('foreningsplugin-attendance', __('Attendance', 'foreningsplugin'), 'attendance-' . $attendance->participantId(), [
                    [__('Meeting', 'foreningsplugin'), $attendance->meetingTitle()],
                    [__('Date', 'foreningsplugin'), $attendance->meetingDate()],
                    [__('Attendance', 'foreningsplugin'), self::presence($attendance->presence())],
                    [__('Task at the meeting', 'foreningsplugin'), self::duty($attendance->duty())],
                ]);
            }

            $groups[] = self::item('foreningsplugin-retained', __('Retained records', 'foreningsplugin'), 'retained-' . $person->id(), [
                [__('Minutes', 'foreningsplugin'), __('Minutes may contain your name and are kept. The minutes text is not included in the export.', 'foreningsplugin')],
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
                $messages[] = __('The contact details are anonymized and the account link is removed.', 'foreningsplugin');
            }

            if ($outcome->publicContactCleared()) {
                $messages[] = __('The public contact on the assignment is removed.', 'foreningsplugin');
            }

            if ($outcome->membershipRetained()) {
                $messages[] = __('The membership periods are kept.', 'foreningsplugin');
            }

            if ($outcome->assignmentRetained()) {
                $messages[] = __('Assignment dates and roles are kept.', 'foreningsplugin');
            }

            if ($outcome->minutesNameRetained()) {
                $messages[] = __('The name in locked minutes is kept.', 'foreningsplugin');
            }

            if ($outcome->signedCopyRetained()) {
                $messages[] = __('The signed scan is kept.', 'foreningsplugin');
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
            PersonStatus::Deceased->value => __('Deceased', 'foreningsplugin'),
            default => __('Known', 'foreningsplugin'),
        };
    }

    private static function membershipStatus(string $status): string
    {
        return match ($status) {
            MembershipStatus::Pending->value => __('Pending', 'foreningsplugin'),
            MembershipStatus::Dormant->value => __('Dormant', 'foreningsplugin'),
            MembershipStatus::Ended->value => __('Ended', 'foreningsplugin'),
            default => __('Active', 'foreningsplugin'),
        };
    }

    private static function presence(string $presence): string
    {
        return match ($presence) {
            Presence::Absent->value => __('Absent', 'foreningsplugin'),
            Presence::CoOpted->value => __('Adjunct', 'foreningsplugin'),
            default => __('Present', 'foreningsplugin'),
        };
    }

    private static function duty(string $duty): string
    {
        return match ($duty) {
            MeetingDuty::Chair->value => __('Chair', 'foreningsplugin'),
            MeetingDuty::Adjuster->value => __('Adjuster', 'foreningsplugin'),
            default => __('None', 'foreningsplugin'),
        };
    }
}
