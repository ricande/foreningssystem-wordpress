<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Association\WorkOverview;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Infrastructure\Persistence\MigrationException;

final class Plugin
{
    public const VERSION = '0.1.0';

    public static function register(string $pluginFile): void
    {
        register_activation_hook($pluginFile, [self::class, 'activate']);

        add_action('plugins_loaded', static function () use ($pluginFile): void {
            load_plugin_textdomain(
                'foreningsplugin',
                false,
                dirname(plugin_basename($pluginFile)) . '/languages'
            );

            if (defined('WP_CLI') && WP_CLI) {
                WordpressMigrations::migrateIfNeeded();
                WordpressAccess::sync();
            }
        });

        add_action('init', [LatestMinutesBlock::class, 'register']);
        add_action('init', [CurrentBoardBlock::class, 'register']);
        add_action('init', [LatestBoardMeetingBlock::class, 'register']);
        add_action('init', [PublicDocumentsBlock::class, 'register']);
        add_action('init', [MemberDocumentsBlock::class, 'register']);
        add_action('init', [MemberCountBlock::class, 'register']);
        WordpressPrivacy::register();
        WordpressRetention::register();
        add_action('template_redirect', [DocumentDownload::class, 'maybeSend']);
        add_action('admin_init', [self::class, 'migrateInAdmin']);
        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_post_assoc_register_person', [MembersPage::class, 'registerPerson']);
        add_action('admin_post_assoc_export_members', [MembersPage::class, 'exportMembers']);
        add_action('admin_post_assoc_import_members', [MembersPage::class, 'importMembers']);
        add_action('admin_post_assoc_end_membership', [MembersPage::class, 'endMembership']);
        add_action('admin_post_assoc_mark_deceased', [MembersPage::class, 'markDeceased']);
        add_action('admin_post_assoc_place_assignment', [BoardPage::class, 'place']);
        add_action('admin_post_assoc_end_assignment', [BoardPage::class, 'end']);
        add_action('admin_post_assoc_schedule_meeting', [MeetingsPage::class, 'schedule']);
        add_action('admin_post_assoc_save_meeting_template', [MeetingsPage::class, 'saveTemplate']);
        add_action('admin_post_assoc_add_template_heading', [MeetingsPage::class, 'addTemplateHeading']);
        add_action('admin_post_assoc_remove_meeting_template', [MeetingsPage::class, 'removeTemplate']);
        add_action('admin_post_assoc_start_meeting', [MeetingsPage::class, 'start']);
        add_action('admin_post_assoc_mark_meeting_held', [MeetingsPage::class, 'markHeld']);
        add_action('admin_post_assoc_add_participant', [MeetingDetailPage::class, 'addParticipant']);
        add_action('admin_post_assoc_remove_participant', [MeetingDetailPage::class, 'removeParticipant']);
        add_action('admin_post_assoc_add_agenda_item', [MeetingDetailPage::class, 'addAgendaItem']);
        add_action('admin_post_assoc_move_agenda_item', [MeetingDetailPage::class, 'moveAgendaItem']);
        add_action('admin_post_assoc_remove_agenda_item', [MeetingDetailPage::class, 'removeAgendaItem']);
        add_action('admin_post_assoc_add_note', [MeetingDetailPage::class, 'addNote']);
        add_action('admin_post_assoc_remove_note', [MeetingDetailPage::class, 'removeNote']);
        add_action('admin_post_assoc_add_decision', [MeetingDetailPage::class, 'addDecision']);
        add_action('admin_post_assoc_set_decision_follow_up', [MeetingDetailPage::class, 'setDecisionFollowUp']);
        add_action('admin_post_assoc_remove_decision', [MeetingDetailPage::class, 'removeDecision']);
        add_action('admin_post_assoc_add_action_item', [MeetingDetailPage::class, 'addActionItem']);
        add_action('admin_post_assoc_set_action_status', [MeetingDetailPage::class, 'setActionStatus']);
        add_action('admin_post_assoc_remove_action_item', [MeetingDetailPage::class, 'removeActionItem']);
        add_action('admin_post_assoc_create_minutes_draft', [MeetingDetailPage::class, 'createMinutesDraft']);
        add_action('admin_post_assoc_replace_minutes_body', [MeetingDetailPage::class, 'replaceMinutesBody']);
        add_action('admin_post_assoc_regenerate_minutes_draft', [MeetingDetailPage::class, 'regenerateMinutesDraft']);
        add_action('admin_post_assoc_submit_minutes', [MeetingDetailPage::class, 'submitMinutes']);
        add_action('admin_post_assoc_send_minutes_back', [MeetingDetailPage::class, 'sendMinutesBack']);
        add_action('admin_post_assoc_finalize_minutes', [MeetingDetailPage::class, 'finalizeMinutes']);
        add_action('admin_post_assoc_open_minutes_correction', [MeetingDetailPage::class, 'openMinutesCorrection']);
        add_action('admin_post_assoc_download_minutes_pdf', [MeetingDetailPage::class, 'downloadMinutesPdf']);
        add_action('admin_post_assoc_print_minutes', [MeetingDetailPage::class, 'printMinutes']);
        add_action('admin_post_assoc_upload_signed_copy', [MeetingDetailPage::class, 'uploadSignedCopy']);
        add_action('admin_post_assoc_download_signed_copy', [MeetingDetailPage::class, 'downloadSignedCopy']);
        add_action('admin_post_assoc_publish_minutes', [MeetingDetailPage::class, 'publishMinutes']);
        add_action('admin_post_assoc_unpublish_minutes', [MeetingDetailPage::class, 'unpublishMinutes']);
        add_action('admin_post_assoc_add_document', [DocumentsPage::class, 'add']);
        add_action('admin_post_assoc_set_document_visibility', [DocumentsPage::class, 'setVisibility']);
        add_action('admin_post_assoc_save_retention', [RetentionPage::class, 'save']);
        add_action('admin_post_assoc_apply_retention_now', [RetentionPage::class, 'apply']);
        add_action('admin_post_assoc_save_minutes_lock', [MinutesLockPage::class, 'save']);
        add_action('admin_post_assoc_save_minutes_publish', [MinutesPublishPage::class, 'save']);
        add_action('admin_post_assoc_save_profile', [AssociationProfilePage::class, 'save']);

        if (defined('WP_CLI') && WP_CLI) {
            Cli::register();
        }
    }

    public static function activate(): void
    {
        WordpressMigrations::runner()->migrate();
        WordpressAccess::sync();
    }

    public static function migrateInAdmin(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        try {
            WordpressMigrations::migrateIfNeeded();
            WordpressAccess::sync();
        } catch (MigrationException $error) {
            add_action('admin_notices', static function () use ($error): void {
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html($error->getMessage())
                );
            });
        }
    }

    public static function registerAdminMenu(): void
    {
        add_menu_page(
            __('Förening', 'foreningsplugin'),
            __('Förening', 'foreningsplugin'),
            Capabilities::VIEW_MEMBERS,
            'foreningsplugin',
            [self::class, 'renderAdminPage'],
            'dashicons-groups',
            26
        );

        add_submenu_page(
            'foreningsplugin',
            __('Medlemmar', 'foreningsplugin'),
            __('Medlemmar', 'foreningsplugin'),
            Capabilities::VIEW_MEMBERS,
            'foreningsplugin-members',
            [MembersPage::class, 'render']
        );

        add_submenu_page(
            'foreningsplugin',
            __('Styrelse', 'foreningsplugin'),
            __('Styrelse', 'foreningsplugin'),
            Capabilities::VIEW_MEMBERS,
            'foreningsplugin-board',
            [BoardPage::class, 'render']
        );

        add_submenu_page(
            'foreningsplugin',
            __('Dokument', 'foreningsplugin'),
            __('Dokument', 'foreningsplugin'),
            Capabilities::VIEW_BOARD_DOCUMENTS,
            'foreningsplugin-documents',
            [DocumentsPage::class, 'render']
        );

        add_submenu_page(
            'foreningsplugin',
            __('Möten', 'foreningsplugin'),
            __('Möten', 'foreningsplugin'),
            Capabilities::VIEW_INTERNAL_MEETINGS,
            'foreningsplugin-meetings',
            [MeetingsPage::class, 'render']
        );

        add_submenu_page(
            'foreningsplugin',
            __('Profil', 'foreningsplugin'),
            __('Profil', 'foreningsplugin'),
            Capabilities::MANAGE_ASSOCIATION,
            'foreningsplugin-profile',
            [AssociationProfilePage::class, 'render']
        );

        add_submenu_page(
            'foreningsplugin',
            __('Kvarhållning', 'foreningsplugin'),
            __('Kvarhållning', 'foreningsplugin'),
            Capabilities::MANAGE_ASSOCIATION,
            'foreningsplugin-retention',
            [RetentionPage::class, 'render']
        );

        add_submenu_page(
            'foreningsplugin',
            __('Låsa protokoll', 'foreningsplugin'),
            __('Låsa protokoll', 'foreningsplugin'),
            Capabilities::MANAGE_ASSOCIATION,
            'foreningsplugin-minutes-lock',
            [MinutesLockPage::class, 'render']
        );

        add_submenu_page(
            'foreningsplugin',
            __('Publicera protokoll', 'foreningsplugin'),
            __('Publicera protokoll', 'foreningsplugin'),
            Capabilities::MANAGE_ASSOCIATION,
            'foreningsplugin-minutes-publish',
            [MinutesPublishPage::class, 'render']
        );
    }

    public static function renderAdminPage(): void
    {
        if (! current_user_can(Capabilities::VIEW_MEMBERS)) {
            return;
        }

        $profile = WordpressAssociationProfile::load();
        $activeMembers = WordpressPeople::service()->activeMemberCount(AssociationDate::fromIso(wp_date('Y-m-d')));
        $currentBoard = WordpressBoard::service()->currentCount(AssociationDate::fromIso(wp_date('Y-m-d')));

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Föreningsplugin', 'foreningsplugin') . '</h1>';

        if ($profile->name() !== '') {
            echo '<p>' . esc_html($profile->name()) . '</p>';
        }

        echo '<p>' . esc_html__('Pluginet är aktivt.', 'foreningsplugin') . '</p>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %d: number of active members */
            __('Aktiva medlemmar: %d', 'foreningsplugin'),
            $activeMembers
        )) . '</p>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %d: number of current board assignments */
            __('Styrelseuppdrag idag: %d', 'foreningsplugin'),
            $currentBoard
        )) . '</p>';

        if (current_user_can(Capabilities::VIEW_INTERNAL_MEETINGS)) {
            $meetings = WordpressMeetings::service();
            $overview = new WorkOverview();
            $now = MeetingMoment::fromLocal(wp_date('Y-m-d H:i:s'));
            $today = AssociationDate::fromIso(wp_date('Y-m-d'));
            $allMeetings = $meetings->listMeetings();
            $next = $overview->nextPlanned($allMeetings, $now);
            $last = $overview->lastHeld($allMeetings);
            echo '<h2>' . esc_html__('Nästa möte', 'foreningsplugin') . '</h2>';
            echo '<p>' . esc_html($next === null ? __('Inget kommande möte.', 'foreningsplugin') : $next->title() . ' ' . $next->startsAt()->date()) . '</p>';
            echo '<h2>' . esc_html__('Senaste hållet möte', 'foreningsplugin') . '</h2>';
            echo '<p>' . esc_html($last === null ? __('Inget hållet möte.', 'foreningsplugin') : $last->title() . ' ' . $last->startsAt()->date()) . '</p>';
            echo '<p>' . esc_html(sprintf(
                /* translators: %d: number of planned meetings */
                __('Planerade möten: %d', 'foreningsplugin'),
                $meetings->countWithStatus(MeetingStatus::Planned)
            )) . '</p>';
            echo '<p>' . esc_html(sprintf(
                /* translators: %d: number of open decisions */
                __('Öppna beslut: %d', 'foreningsplugin'),
                WordpressMeetings::record()->openCount()
            )) . '</p>';
            echo '<h2>' . esc_html__('Försenade uppgifter', 'foreningsplugin') . '</h2>';
            $overdue = array_slice($overview->overdue(WordpressMeetings::record()->actions(), $today), 0, 8);

            if ($overdue === []) {
                echo '<p>' . esc_html__('Inga försenade uppgifter.', 'foreningsplugin') . '</p>';
            } else {
                echo '<ul>';

                foreach ($overdue as $item) {
                    $due = $item->dueOn();
                    echo '<li>' . esc_html($item->task() . ($due === null ? '' : ' (' . $due->iso() . ')')) . '</li>';
                }

                echo '</ul>';
            }
        }

        if (current_user_can(Capabilities::VIEW_BOARD_DOCUMENTS) || current_user_can(Capabilities::MANAGE_DOCUMENTS)) {
            $documents = WordpressDocuments::archive()->officerList();
            usort($documents, static fn ($left, $right): int => $right->id() <=> $left->id());
            echo '<h2>' . esc_html__('Senaste dokument', 'foreningsplugin') . '</h2>';

            if ($documents === []) {
                echo '<p>' . esc_html__('Inga dokument att visa.', 'foreningsplugin') . '</p>';
            } else {
                echo '<ul>';

                foreach (array_slice($documents, 0, 5) as $document) {
                    echo '<li>' . esc_html($document->title()) . '</li>';
                }

                echo '</ul>';
            }
        }

        echo '</div>';
    }
}
