<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Access\Capabilities;
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

        add_action('admin_init', [self::class, 'migrateInAdmin']);
        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_post_assoc_register_person', [MembersPage::class, 'registerPerson']);
        add_action('admin_post_assoc_end_membership', [MembersPage::class, 'endMembership']);
        add_action('admin_post_assoc_mark_deceased', [MembersPage::class, 'markDeceased']);
        add_action('admin_post_assoc_place_assignment', [BoardPage::class, 'place']);
        add_action('admin_post_assoc_end_assignment', [BoardPage::class, 'end']);
        add_action('admin_post_assoc_schedule_meeting', [MeetingsPage::class, 'schedule']);
        add_action('admin_post_assoc_start_meeting', [MeetingsPage::class, 'start']);
        add_action('admin_post_assoc_mark_meeting_held', [MeetingsPage::class, 'markHeld']);
        add_action('admin_post_assoc_add_participant', [MeetingDetailPage::class, 'addParticipant']);
        add_action('admin_post_assoc_remove_participant', [MeetingDetailPage::class, 'removeParticipant']);
        add_action('admin_post_assoc_add_agenda_item', [MeetingDetailPage::class, 'addAgendaItem']);
        add_action('admin_post_assoc_move_agenda_item', [MeetingDetailPage::class, 'moveAgendaItem']);
        add_action('admin_post_assoc_remove_agenda_item', [MeetingDetailPage::class, 'removeAgendaItem']);

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
            __('Möten', 'foreningsplugin'),
            __('Möten', 'foreningsplugin'),
            Capabilities::VIEW_INTERNAL_MEETINGS,
            'foreningsplugin-meetings',
            [MeetingsPage::class, 'render']
        );
    }

    public static function renderAdminPage(): void
    {
        if (! current_user_can(Capabilities::VIEW_MEMBERS)) {
            return;
        }

        $activeMembers = WordpressPeople::service()->activeMemberCount();
        $currentBoard = WordpressBoard::service()->currentCount(AssociationDate::fromIso(wp_date('Y-m-d')));

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Föreningsplugin', 'foreningsplugin') . '</h1>';
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
            echo '<p>' . esc_html(sprintf(
                /* translators: %d: number of planned meetings */
                __('Planerade möten: %d', 'foreningsplugin'),
                WordpressMeetings::service()->countWithStatus(MeetingStatus::Planned)
            )) . '</p>';
        }
        echo '<p>' . esc_html(sprintf(
            /* translators: %d: schema version */
            __('Databasschema: %d', 'foreningsplugin'),
            (int) get_option(WordpressSchemaVersionStore::OPTION, 0)
        )) . '</p>';
        echo '</div>';
    }
}
