<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Access\Capabilities;
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
        add_action('init', [MemberAreaBlock::class, 'register']);
        add_action('init', [MemberCountBlock::class, 'register']);
        WordpressPrivacy::register();
        PrivateStorageWarning::register();
        WordpressRetention::register();
        WordpressMemberAccounts::register();
        add_action('template_redirect', [DocumentDownload::class, 'maybeSend']);
        add_action('admin_init', [self::class, 'migrateInAdmin']);
        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_post_assoc_register_person', [MembersPage::class, 'registerPerson']);
        add_action('admin_post_assoc_export_members', [MembersPage::class, 'exportMembers']);
        add_action('admin_post_assoc_import_members', [MembersPage::class, 'importMembers']);
        add_action('admin_post_assoc_end_membership', [MembersPage::class, 'endMembership']);
        add_action('admin_post_assoc_add_membership', [MembersPage::class, 'addMembership']);
        add_action('admin_post_assoc_register_company', [MembersPage::class, 'registerCompany']);
        add_action('admin_post_assoc_add_family_participant', [MembersPage::class, 'addFamilyParticipant']);
        add_action('admin_post_assoc_store_identity', [MembersPage::class, 'storeIdentity']);
        add_action('admin_post_assoc_remove_identity', [MembersPage::class, 'removeIdentity']);
        add_action('admin_post_assoc_add_guardian', [MembersPage::class, 'addGuardian']);
        add_action('admin_post_assoc_record_guardian_approval', [MembersPage::class, 'recordGuardianApproval']);
        add_action('admin_post_assoc_export_member_structure', [MembersPage::class, 'exportStructure']);
        add_action('admin_post_assoc_mark_deceased', [MembersPage::class, 'markDeceased']);
        add_action('admin_post_assoc_open_membership', [MembersPage::class, 'openExisting']);
        add_action('admin_post_assoc_update_person', [MembersPage::class, 'updatePerson']);
        add_action('admin_post_assoc_create_member_account', [MembersPage::class, 'createMemberAccount']);
        add_action('admin_post_assoc_link_member_account', [MembersPage::class, 'linkMemberAccount']);
        add_action('admin_post_assoc_unlink_member_account', [MembersPage::class, 'unlinkMemberAccount']);
        add_action('admin_post_assoc_clear_broken_member_account', [MembersPage::class, 'clearBrokenMemberAccount']);
        add_action('admin_post_assoc_find_member_account', [MembersPage::class, 'findMemberAccount']);
        add_action('admin_post_assoc_end_participation', [MembersPage::class, 'endParticipation']);
        add_action('admin_post_assoc_add_company_contact', [MembersPage::class, 'addCompanyContact']);
        add_action('admin_post_assoc_end_guardian', [MembersPage::class, 'endGuardian']);
        add_action('admin_post_assoc_withdraw_guardian_approval', [MembersPage::class, 'withdrawGuardianApproval']);
        add_action('admin_post_assoc_place_assignment', [BoardPage::class, 'place']);
        add_action('admin_post_assoc_end_assignment', [BoardPage::class, 'end']);
        add_action('admin_post_assoc_cancel_assignment', [BoardPage::class, 'cancelScheduled']);
        add_action('admin_post_assoc_schedule_meeting', [MeetingsPage::class, 'schedule']);
        add_action('admin_post_assoc_save_meeting_template', [MeetingsPage::class, 'saveTemplate']);
        add_action('admin_post_assoc_add_template_heading', [MeetingsPage::class, 'addTemplateHeading']);
        add_action('admin_post_assoc_remove_meeting_template', [MeetingsPage::class, 'removeTemplate']);
        add_action('admin_post_assoc_start_meeting', [MeetingsPage::class, 'start']);
        add_action('admin_post_assoc_mark_meeting_held', [MeetingsPage::class, 'markHeld']);
        add_action('admin_post_assoc_save_meeting_header', [MeetingDetailPage::class, 'saveHeader']);
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
        // Every officer bundle already includes view_members. The page then hides
        // meeting and document sections the current user cannot open.
        add_menu_page(
            __('Association', 'foreningsplugin'),
            __('Association', 'foreningsplugin'),
            Capabilities::VIEW_MEMBERS,
            'foreningsplugin',
            [self::class, 'renderAdminPage'],
            'dashicons-groups',
            26
        );

        add_submenu_page(
            'foreningsplugin',
            __('Members', 'foreningsplugin'),
            __('Members', 'foreningsplugin'),
            Capabilities::VIEW_MEMBERS,
            'foreningsplugin-members',
            [MembersPage::class, 'render']
        );

        add_submenu_page(
            'foreningsplugin',
            __('Board', 'foreningsplugin'),
            __('Board', 'foreningsplugin'),
            Capabilities::VIEW_MEMBERS,
            'foreningsplugin-board',
            [BoardPage::class, 'render']
        );

        add_submenu_page(
            'foreningsplugin',
            __('Documents', 'foreningsplugin'),
            __('Documents', 'foreningsplugin'),
            Capabilities::VIEW_BOARD_DOCUMENTS,
            'foreningsplugin-documents',
            [DocumentsPage::class, 'render']
        );

        add_submenu_page(
            'foreningsplugin',
            __('Meetings', 'foreningsplugin'),
            __('Meetings', 'foreningsplugin'),
            Capabilities::VIEW_INTERNAL_MEETINGS,
            'foreningsplugin-meetings',
            [MeetingsPage::class, 'render']
        );

        add_submenu_page(
            'foreningsplugin',
            __('Profile', 'foreningsplugin'),
            __('Profile', 'foreningsplugin'),
            Capabilities::MANAGE_ASSOCIATION,
            'foreningsplugin-profile',
            [AssociationProfilePage::class, 'render']
        );

        add_submenu_page(
            'foreningsplugin',
            __('Retention', 'foreningsplugin'),
            __('Retention', 'foreningsplugin'),
            Capabilities::MANAGE_ASSOCIATION,
            'foreningsplugin-retention',
            [RetentionPage::class, 'render']
        );

        add_submenu_page(
            'foreningsplugin',
            __('Lock minutes', 'foreningsplugin'),
            __('Lock minutes', 'foreningsplugin'),
            Capabilities::MANAGE_ASSOCIATION,
            'foreningsplugin-minutes-lock',
            [MinutesLockPage::class, 'render']
        );

        add_submenu_page(
            'foreningsplugin',
            __('Publish minutes', 'foreningsplugin'),
            __('Publish minutes', 'foreningsplugin'),
            Capabilities::MANAGE_ASSOCIATION,
            'foreningsplugin-minutes-publish',
            [MinutesPublishPage::class, 'render']
        );
    }

    public static function renderAdminPage(): void
    {
        AssociationOverviewPage::render();
    }
}
