<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Infrastructure\WordPress\MeetingDetailPage;
use Foreningssystem\Tests\Support\AdminHalt;
use Foreningssystem\Tests\Support\WordPressRequest;
use PHPUnit\Framework\TestCase;

/**
 * The meeting admin-post handlers are run here, not read. A handler that calls a route
 * helper that does not exist fails these tests instead of reaching an officer's browser.
 */
final class MeetingAdminActionsTest extends TestCase
{
    /**
     * Handler => the nonce it must check and the capability sets that may use it.
     *
     * @return array<string, array{nonce: string, accepts: list<list<string>>}>
     */
    private function guardedActions(): array
    {
        $meeting = [[Capabilities::MANAGE_MEETINGS], [Capabilities::RECORD_MEETING]];
        $record = [[Capabilities::RECORD_MEETING]];
        $finalize = [[Capabilities::FINALIZE_MINUTES]];
        $publish = [[Capabilities::PUBLISH_MINUTES]];

        return [
            'saveHeader' => ['nonce' => 'assoc_save_meeting_header', 'accepts' => $meeting],
            'addParticipant' => ['nonce' => 'assoc_add_participant', 'accepts' => $meeting],
            'removeParticipant' => ['nonce' => 'assoc_remove_participant', 'accepts' => $meeting],
            'addAgendaItem' => ['nonce' => 'assoc_add_agenda_item', 'accepts' => $meeting],
            'moveAgendaItem' => ['nonce' => 'assoc_move_agenda_item', 'accepts' => $meeting],
            'removeAgendaItem' => ['nonce' => 'assoc_remove_agenda_item', 'accepts' => $meeting],
            'addNote' => ['nonce' => 'assoc_add_note', 'accepts' => $record],
            'removeNote' => ['nonce' => 'assoc_remove_note', 'accepts' => $record],
            'addDecision' => ['nonce' => 'assoc_add_decision', 'accepts' => $record],
            'setDecisionFollowUp' => ['nonce' => 'assoc_set_decision_follow_up', 'accepts' => $record],
            'removeDecision' => ['nonce' => 'assoc_remove_decision', 'accepts' => $record],
            'addActionItem' => ['nonce' => 'assoc_add_action_item', 'accepts' => $record],
            'setActionStatus' => ['nonce' => 'assoc_set_action_status', 'accepts' => $record],
            'removeActionItem' => ['nonce' => 'assoc_remove_action_item', 'accepts' => $record],
            'createMinutesDraft' => ['nonce' => 'assoc_create_minutes_draft', 'accepts' => $record],
            'replaceMinutesBody' => ['nonce' => 'assoc_replace_minutes_body', 'accepts' => $record],
            'regenerateMinutesDraft' => ['nonce' => 'assoc_regenerate_minutes_draft', 'accepts' => $record],
            'submitMinutes' => ['nonce' => 'assoc_submit_minutes', 'accepts' => $record],
            'sendMinutesBack' => ['nonce' => 'assoc_send_minutes_back', 'accepts' => $record],
            'finalizeMinutes' => ['nonce' => 'assoc_finalize_minutes', 'accepts' => $finalize],
            'openMinutesCorrection' => ['nonce' => 'assoc_open_minutes_correction', 'accepts' => $finalize],
            'publishMinutes' => ['nonce' => 'assoc_publish_minutes', 'accepts' => $publish],
            'unpublishMinutes' => ['nonce' => 'assoc_unpublish_minutes', 'accepts' => $publish],
            'uploadSignedCopy' => [
                'nonce' => 'assoc_upload_signed_copy',
                'accepts' => [[Capabilities::FINALIZE_MINUTES, Capabilities::MANAGE_DOCUMENTS]],
            ],
        ];
    }

    /**
     * Reading handlers. The application service decides who may read; the route only has
     * to prove the request came from the association screens.
     *
     * @return array<string, string>
     */
    private function readActions(): array
    {
        return [
            'downloadMinutesPdf' => 'assoc_download_minutes_pdf',
            'printMinutes' => 'assoc_print_minutes',
            'downloadSignedCopy' => 'assoc_download_signed_copy',
        ];
    }

    protected function setUp(): void
    {
        WordPressRequest::reset();
    }

    protected function tearDown(): void
    {
        WordPressRequest::reset();
    }

    public function test_every_registered_meeting_action_is_covered_here(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/Plugin.php');
        $found = preg_match_all(
            "/add_action\('admin_post_(\w+)', \[MeetingDetailPage::class, '(\w+)'\]\)/",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        self::assertIsInt($found);
        self::assertGreaterThan(20, $found);

        $registered = [];

        foreach ($matches as $match) {
            $registered[$match[2]] = $match[1];
        }

        $covered = array_merge(
            array_map(static fn (array $action): string => $action['nonce'], $this->guardedActions()),
            $this->readActions()
        );

        ksort($registered);
        ksort($covered);

        self::assertSame($registered, $covered, 'A meeting admin-post action is registered without a guard test.');
    }

    public function test_a_guarded_action_refuses_before_it_looks_at_the_nonce(): void
    {
        foreach ($this->guardedActions() as $method => $action) {
            WordPressRequest::reset();
            WordPressRequest::acceptNonce($action['nonce']);

            try {
                MeetingDetailPage::$method();
                self::fail($method . ' ran without any capability.');
            } catch (AdminHalt $halt) {
                self::assertSame(403, $halt->status(), $method . ' denied with the wrong status.');
            }

            self::assertFalse(
                WordPressRequest::called('check_admin_referer:' . $action['nonce']),
                $method . ' checked the nonce before the capability.'
            );
        }
    }

    public function test_each_action_accepts_only_the_capability_that_belongs_to_it(): void
    {
        foreach ($this->guardedActions() as $method => $action) {
            foreach (Capabilities::all() as $capability) {
                WordPressRequest::reset();
                WordPressRequest::allow($capability);

                try {
                    MeetingDetailPage::$method();
                    self::fail($method . ' ran without a valid nonce.');
                } catch (AdminHalt) {
                    // Either the capability or the missing nonce stopped the request.
                }

                $reached = WordPressRequest::called('check_admin_referer:' . $action['nonce']);
                $allowed = in_array([$capability], $action['accepts'], true);

                self::assertSame(
                    $allowed,
                    $reached,
                    $method . ' with only ' . $capability . ' should ' . ($allowed ? 'pass' : 'fail') . ' the capability check.'
                );
            }
        }
    }

    public function test_a_signed_copy_needs_both_the_minutes_right_and_the_document_right(): void
    {
        WordPressRequest::reset();
        WordPressRequest::allow(Capabilities::FINALIZE_MINUTES, Capabilities::MANAGE_DOCUMENTS);

        try {
            MeetingDetailPage::uploadSignedCopy();
            self::fail('The signed copy upload ran without a valid nonce.');
        } catch (AdminHalt) {
            // The nonce is what stops the request now.
        }

        self::assertTrue(WordPressRequest::called('check_admin_referer:assoc_upload_signed_copy'));
        self::assertTrue(WordPressRequest::called('current_user_can:' . Capabilities::FINALIZE_MINUTES));
        self::assertTrue(WordPressRequest::called('current_user_can:' . Capabilities::MANAGE_DOCUMENTS));
    }

    public function test_a_guarded_action_stops_on_a_missing_nonce_even_with_the_capability(): void
    {
        foreach ($this->guardedActions() as $method => $action) {
            foreach ($action['accepts'] as $capabilities) {
                WordPressRequest::reset();
                WordPressRequest::allow(...$capabilities);

                try {
                    MeetingDetailPage::$method();
                    self::fail($method . ' ran without a valid nonce.');
                } catch (AdminHalt $halt) {
                    self::assertSame(403, $halt->status(), $method . ' answered a bad nonce with the wrong status.');
                }

                self::assertTrue(
                    WordPressRequest::called('check_admin_referer:' . $action['nonce']),
                    $method . ' did not check its own nonce.'
                );
            }
        }
    }

    public function test_a_reading_action_checks_its_own_nonce(): void
    {
        foreach ($this->readActions() as $method => $nonce) {
            WordPressRequest::reset();

            try {
                MeetingDetailPage::$method();
                self::fail($method . ' ran without a valid nonce.');
            } catch (AdminHalt) {
                // The nonce stopped the request.
            }

            self::assertTrue(
                WordPressRequest::called('check_admin_referer:' . $nonce),
                $method . ' did not check its own nonce.'
            );
        }
    }
}
