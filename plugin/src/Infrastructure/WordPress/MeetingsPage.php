<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingRuleException;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MeetingTemplate;
use Foreningssystem\Domain\Meeting\MeetingType;

final class MeetingsPage
{
    public static function schedule(): void
    {
        self::guardManage('assoc_schedule_meeting');

        $typeId = self::integer('type_id');
        $templateId = self::integer('template_id');

        try {
            if ($templateId > 0) {
                WordpressMeetings::templates()->assertForType($templateId, $typeId);
            }

            $meetingId = WordpressMeetings::service()->schedule(
                $typeId,
                self::text('title'),
                MeetingMoment::fromLocal(self::text('meeting_date') . ' ' . self::text('meeting_time')),
                self::text('place')
            );

            if ($templateId > 0) {
                WordpressMeetings::templates()->copyOnto($meetingId, $templateId);
            }

            self::redirect('scheduled');
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to plan meetings.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (MeetingRuleException | \InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function saveTemplate(): void
    {
        self::guardManage('assoc_save_meeting_template');

        try {
            WordpressMeetings::templates()->create(self::integer('type_id'), self::text('name'));
            self::redirect('template_saved');
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to plan meetings.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (MeetingRuleException | \InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function addTemplateHeading(): void
    {
        self::guardManage('assoc_add_template_heading');

        try {
            WordpressMeetings::templates()->addHeading(self::integer('template_id'), self::text('title'));
            self::redirect('template_saved');
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to plan meetings.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (MeetingRuleException | \InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function removeTemplate(): void
    {
        self::guardManage('assoc_remove_meeting_template');

        try {
            WordpressMeetings::templates()->remove(self::integer('template_id'));
            self::redirect('template_removed');
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to plan meetings.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (MeetingRuleException | \InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function start(): void
    {
        self::guardRecord('assoc_start_meeting');

        $meetingId = self::integer('meeting_id');

        try {
            WordpressMeetings::service()->start($meetingId);
            self::redirect('started', $meetingId);
        } catch (MeetingRuleException $error) {
            self::redirect(self::noticeCode($error), $meetingId);
        }
    }

    public static function markHeld(): void
    {
        self::guardRecord('assoc_mark_meeting_held');

        $meetingId = self::integer('meeting_id');

        if (self::text('confirm') !== '1') {
            self::redirect('confirm_held', $meetingId);
        }

        try {
            WordpressMeetings::service()->markHeld($meetingId);
            self::redirect('held', $meetingId);
        } catch (MeetingRuleException $error) {
            self::redirect(self::noticeCode($error), $meetingId);
        }
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::VIEW_INTERNAL_MEETINGS)) {
            wp_die(esc_html__('You do not have permission to view meetings.', 'foreningsplugin'));
        }

        $meetingId = isset($_GET['meeting']) ? absint($_GET['meeting']) : 0;

        if ($meetingId > 0) {
            MeetingDetailPage::render($meetingId);

            return;
        }

        $service = WordpressMeetings::service();
        $canManage = current_user_can(Capabilities::MANAGE_MEETINGS);
        $canRecord = $canManage || current_user_can(Capabilities::RECORD_MEETING);
        $types = [];

        foreach ($service->types() as $type) {
            if ($type->id() !== null) {
                $types[$type->id()] = $type;
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Meetings', 'foreningsplugin') . '</h1>';
        echo '<p>' . esc_html__('A held meeting is not minutes. Notes are working material until minutes are created.', 'foreningsplugin') . '</p>';
        self::notice();

        if ($canManage) {
            echo '<h2>' . esc_html__('New meeting', 'foreningsplugin') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_schedule_meeting">';
            wp_nonce_field('assoc_schedule_meeting');
            echo '<p><label>' . esc_html__('Type', 'foreningsplugin') . ' <select name="type_id" required>';
            echo '<option value="">' . esc_html__('Choose type', 'foreningsplugin') . '</option>';

            foreach ($types as $type) {
                echo '<option value="' . esc_attr((string) $type->id()) . '">' . esc_html(self::typeLabel($type)) . '</option>';
            }

            echo '</select></label></p>';
            $templateService = WordpressMeetings::templates();
            echo '<p class="description">' . esc_html__('Template headings are copied into this meeting. Changing the template later does not rewrite the meeting. A template is not a complete legal annual-meeting agenda.', 'foreningsplugin') . '</p>';
            echo '<p><label>' . esc_html__('Template', 'foreningsplugin') . ' <select name="template_id">';
            echo '<option value="0">' . esc_html__('No template', 'foreningsplugin') . '</option>';

            foreach ($templateService->all() as $template) {
                $type = $types[$template->typeId()] ?? null;
                $label = ($type instanceof MeetingType ? self::typeLabel($type) : '') . ': ' . $template->name();
                echo '<option value="' . esc_attr((string) $template->id()) . '">' . esc_html($label) . '</option>';
            }

            echo '</select></label></p>';
            self::field('title', __('Title', 'foreningsplugin'), 'text', true);
            self::field('meeting_date', __('Date', 'foreningsplugin'), 'date', true);
            self::field('meeting_time', __('Time', 'foreningsplugin'), 'time', true);
            self::field('place', __('Place', 'foreningsplugin'), 'text', false);
            submit_button(__('Save meeting', 'foreningsplugin'));
            echo '</form>';
            echo '<details><summary>' . esc_html__('Meeting templates', 'foreningsplugin') . '</summary>';
            self::templateEditor($types, $templateService);
            echo '</details>';
        }

        $sections = (new \Foreningssystem\Application\Meeting\MeetingOverview())->sections($service->listMeetings());

        if ($sections['in_progress'] === [] && $sections['planned'] === [] && $sections['held'] === []) {
            echo '<h2>' . esc_html__('No meetings yet.', 'foreningsplugin') . '</h2>';

            if ($canManage) {
                echo '<p>' . esc_html__('Create the association\'s first meeting.', 'foreningsplugin') . '</p>';
            }
        }

        self::meetingSection(__('In progress', 'foreningsplugin'), $sections['in_progress'], $types, $canRecord, 'in_progress');
        self::meetingSection(__('Upcoming', 'foreningsplugin'), $sections['planned'], $types, $canRecord, 'planned');
        self::meetingSection(__('Held', 'foreningsplugin'), $sections['held'], $types, $canRecord, 'held');
        echo '</div>';
    }

    /**
     * @param list<\Foreningssystem\Domain\Meeting\Meeting> $meetings
     * @param array<int, MeetingType> $types
     */
    private static function meetingSection(string $heading, array $meetings, array $types, bool $canRecord, string $kind): void
    {
        if ($meetings === []) {
            return;
        }

        echo '<h2 id="assoc-meetings-' . esc_attr($kind) . '">' . esc_html($heading) . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';

        foreach ([__('Meeting', 'foreningsplugin'), __('Type', 'foreningsplugin'), __('Time', 'foreningsplugin'), __('Place', 'foreningsplugin'), __('State', 'foreningsplugin')] as $column) {
            echo '<th>' . esc_html($column) . '</th>';
        }

        echo '<th>' . esc_html__('Action', 'foreningsplugin') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($meetings as $meeting) {
            $type = $types[$meeting->typeId()] ?? null;
            $meetingUrl = add_query_arg([
                'page' => 'foreningsplugin-meetings',
                'meeting' => (int) $meeting->id(),
            ], admin_url('admin.php'));
            echo '<tr>';
            echo '<td><a href="' . esc_url($meetingUrl) . '">' . esc_html($meeting->title()) . '</a></td>';
            echo '<td>' . esc_html($type instanceof MeetingType ? self::typeLabel($type) : '') . '</td>';
            echo '<td>' . esc_html($meeting->startsAt()->date() . ' ' . $meeting->startsAt()->time()) . '</td>';
            echo '<td>' . esc_html($meeting->place()) . '</td>';
            echo '<td>' . esc_html(self::statusLabel($meeting->status())) . '</td>';
            echo '<td>';

            if ($kind === 'planned') {
                echo '<a class="button" href="' . esc_url($meetingUrl) . '">' . esc_html__('Open meeting', 'foreningsplugin') . '</a> ';

                if ($canRecord) {
                    self::transitionForm((int) $meeting->id(), 'assoc_start_meeting', __('Start meeting', 'foreningsplugin'), false);
                }
            }

            if ($kind === 'in_progress') {
                echo '<a class="button button-primary" href="' . esc_url($meetingUrl) . '">' . esc_html__('Continue meeting', 'foreningsplugin') . '</a> ';

                if ($canRecord) {
                    self::transitionForm((int) $meeting->id(), 'assoc_mark_meeting_held', __('Mark as held', 'foreningsplugin'), true);
                }
            }

            if ($kind === 'held') {
                echo '<a class="button" href="' . esc_url($meetingUrl) . '">' . esc_html__('Open meeting', 'foreningsplugin') . '</a> ';
                echo '<a class="button" href="' . esc_url($meetingUrl . '#assoc-minutes') . '">' . esc_html__('Review minutes', 'foreningsplugin') . '</a>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<int, MeetingType> $types
     */
    private static function templateEditor(array $types, \Foreningssystem\Application\Meeting\MeetingTemplates $templates): void
    {
        echo '<h3>' . esc_html__('Templates', 'foreningsplugin') . '</h3>';
        echo '<p>' . esc_html__('A template is a list of headings. It is copied in when the meeting is created. A later change to the template does not change the meeting, and the template does not contain a ready-made annual-meeting agenda.', 'foreningsplugin') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_save_meeting_template">';
        wp_nonce_field('assoc_save_meeting_template');
        echo '<p><label>' . esc_html__('Type', 'foreningsplugin') . ' <select name="type_id" required>';
        echo '<option value="">' . esc_html__('Choose type', 'foreningsplugin') . '</option>';

        foreach ($types as $type) {
            echo '<option value="' . esc_attr((string) $type->id()) . '">' . esc_html(self::typeLabel($type)) . '</option>';
        }

        echo '</select></label></p>';
        self::field('name', __('Name', 'foreningsplugin'), 'text', true);
        submit_button(__('Save template', 'foreningsplugin'));
        echo '</form>';

        foreach ($templates->all() as $template) {
            if (! $template instanceof MeetingTemplate || $template->id() === null) {
                continue;
            }

            $type = $types[$template->typeId()] ?? null;
            echo '<h3>' . esc_html(($type instanceof MeetingType ? self::typeLabel($type) . ': ' : '') . $template->name()) . '</h3>';
            echo '<ol>';

            foreach ($templates->headings((int) $template->id()) as $heading) {
                echo '<li>' . esc_html($heading->title()) . '</li>';
            }

            echo '</ol>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_add_template_heading">';
            echo '<input type="hidden" name="template_id" value="' . esc_attr((string) $template->id()) . '">';
            wp_nonce_field('assoc_add_template_heading');
            self::field('title', __('Heading', 'foreningsplugin'), 'text', true);
            submit_button(__('Add heading', 'foreningsplugin'), 'secondary');
            echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_remove_meeting_template">';
            echo '<input type="hidden" name="template_id" value="' . esc_attr((string) $template->id()) . '">';
            wp_nonce_field('assoc_remove_meeting_template');
            submit_button(__('Remove template', 'foreningsplugin'), 'delete');
            echo '</form>';
        }
    }

    private static function transitionForm(int $meetingId, string $action, string $label, bool $confirmHeld): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:0.5em">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="meeting_id" value="' . esc_attr((string) $meetingId) . '">';
        echo '<input type="hidden" name="return_meeting" value="' . esc_attr((string) $meetingId) . '">';
        wp_nonce_field($action);

        if ($confirmHeld) {
            echo '<label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__('Mark this meeting as held', 'foreningsplugin') . '</label> ';
        }

        submit_button($label, 'secondary', 'submit', false);
        echo '</form>';
    }

    private static function guardManage(string $nonce): void
    {
        if (! current_user_can(Capabilities::MANAGE_MEETINGS)) {
            wp_die(esc_html__('You do not have permission to plan meetings.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    private static function guardRecord(string $nonce): void
    {
        if (! current_user_can(Capabilities::RECORD_MEETING) && ! current_user_can(Capabilities::MANAGE_MEETINGS)) {
            wp_die(esc_html__('You do not have permission to record the meeting.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    private static function redirect(string $notice, int $meetingId = 0): void
    {
        $args = [
            'page' => 'foreningsplugin-meetings',
            'assoc_notice' => $notice,
        ];

        if ($meetingId > 0) {
            $args['meeting'] = $meetingId;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private static function noticeCode(MeetingRuleException $error): string
    {
        return match ($error->getMessage()) {
            'Only a planned meeting can be started.' => 'not_planned',
            'The meeting is already held.' => 'already_held',
            default => 'invalid',
        };
    }

    private static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';
        $messages = [
            'scheduled' => __('The meeting is saved as planned.', 'foreningsplugin'),
            'template_saved' => __('The template is saved. Meetings already created do not change.', 'foreningsplugin'),
            'template_removed' => __('The template is removed. Meetings already created keep their agenda.', 'foreningsplugin'),
            'started' => __('The meeting is in progress.', 'foreningsplugin'),
            'held' => __('The meeting is marked as held.', 'foreningsplugin'),
            'not_planned' => __('Only a planned meeting can be started.', 'foreningsplugin'),
            'already_held' => __('The meeting is already held.', 'foreningsplugin'),
            'confirm_held' => __('Confirm before marking the meeting as held. Notes, decisions and tasks remain available for minutes preparation.', 'foreningsplugin'),
            'invalid' => __('Check the details and try again.', 'foreningsplugin'),
        ];

        if (! isset($messages[$notice])) {
            return;
        }

        $class = in_array($notice, ['scheduled', 'started', 'held', 'template_saved', 'template_removed'], true) ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }

    private static function typeLabel(MeetingType $type): string
    {
        return match ($type->slug()) {
            'board_meeting' => __('Board meeting', 'foreningsplugin'),
            'annual_meeting' => __('Annual meeting', 'foreningsplugin'),
            'extraordinary_annual_meeting' => __('Extraordinary annual meeting', 'foreningsplugin'),
            'member_meeting' => __('Member meeting', 'foreningsplugin'),
            'working_meeting' => __('Working meeting', 'foreningsplugin'),
            default => $type->name(),
        };
    }

    private static function statusLabel(MeetingStatus $status): string
    {
        return match ($status) {
            MeetingStatus::Planned => __('Planned', 'foreningsplugin'),
            MeetingStatus::InProgress => __('In progress', 'foreningsplugin'),
            MeetingStatus::Held => __('Held', 'foreningsplugin'),
        };
    }

    private static function field(string $name, string $label, string $type, bool $required): void
    {
        echo '<p><label>' . esc_html($label) . ' ';
        echo '<input class="regular-text" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '"' . ($required ? ' required' : '') . '>';
        echo '</label></p>';
    }

    private static function text(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    }

    private static function integer(string $key): int
    {
        $value = $_POST[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
