<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Meeting\MeetingStarterCatalog;
use Foreningssystem\Application\Meeting\MeetingTemplates;
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
            self::redirectNewMeeting('invalid');
        }
    }

    public static function continueTemplateGuide(): void
    {
        self::guardManage('assoc_template_guide');
        $starter = MeetingStarterCatalog::find(self::text('starter'));

        try {
            if ($starter === null) {
                throw new MeetingRuleException('Meeting template was not found.');
            }

            if ($starter->headings() === []) {
                $templateId = WordpressMeetings::templates()->createFromStarter(
                    $starter->key(),
                    __($starter->label(), 'foreningsplugin'),
                    self::integer('type_id'),
                    []
                );
                self::redirectTemplate('template_saved', $templateId);
            }

            self::redirectGuide('items', $starter->key());
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to plan meetings.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (MeetingRuleException | \InvalidArgumentException) {
            self::redirectGuide('choose', '', true);
        }
    }

    public static function saveTemplate(): void
    {
        self::guardManage('assoc_save_meeting_template');
        $starter = MeetingStarterCatalog::find(self::text('starter'));

        try {
            if ($starter === null || $starter->headings() === []) {
                throw new MeetingRuleException('Meeting template was not found.');
            }

            $templateId = WordpressMeetings::templates()->createFromStarter(
                $starter->key(),
                __($starter->label(), 'foreningsplugin'),
                self::integer('type_id'),
                self::selectedHeadings($starter)
            );
            self::redirectTemplate('template_saved', $templateId);
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to plan meetings.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (MeetingRuleException | \InvalidArgumentException) {
            self::redirectGuide('items', $starter?->key() ?? '', true);
        }
    }

    public static function renameTemplate(): void
    {
        self::guardManage('assoc_rename_meeting_template');
        $templateId = self::integer('template_id');

        try {
            WordpressMeetings::templates()->rename($templateId, self::text('name'));
            self::redirectTemplate('template_saved', $templateId);
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to plan meetings.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (MeetingRuleException | \InvalidArgumentException) {
            self::redirectTemplate('invalid', $templateId);
        }
    }

    public static function addTemplateHeading(): void
    {
        self::guardManage('assoc_add_template_heading');
        $templateId = self::integer('template_id');

        try {
            WordpressMeetings::templates()->addHeading($templateId, self::text('title'));
            self::redirectTemplate('template_saved', $templateId);
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to plan meetings.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (MeetingRuleException | \InvalidArgumentException) {
            self::redirectTemplate('invalid', $templateId);
        }
    }

    public static function renameTemplateHeading(): void
    {
        self::guardManage('assoc_rename_template_heading');
        $templateId = self::integer('template_id');

        try {
            WordpressMeetings::templates()->renameHeading($templateId, self::integer('item_id'), self::text('title'));
            self::redirectTemplate('template_saved', $templateId);
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to plan meetings.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (MeetingRuleException | \InvalidArgumentException) {
            self::redirectTemplate('invalid', $templateId);
        }
    }

    public static function removeTemplateHeading(): void
    {
        self::guardManage('assoc_remove_template_heading');
        $templateId = self::integer('template_id');

        try {
            WordpressMeetings::templates()->removeHeading($templateId, self::integer('item_id'));
            self::redirectTemplate('template_saved', $templateId);
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to plan meetings.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (MeetingRuleException | \InvalidArgumentException) {
            self::redirectTemplate('invalid', $templateId);
        }
    }

    public static function moveTemplateHeading(): void
    {
        self::guardManage('assoc_move_template_heading');
        $templateId = self::integer('template_id');
        $direction = self::text('direction');

        try {
            if ($direction !== 'up' && $direction !== 'down') {
                throw new MeetingRuleException('The agenda item cannot move that way.');
            }

            WordpressMeetings::templates()->moveHeading($templateId, self::integer('item_id'), $direction === 'up' ? -1 : 1);
            self::redirectTemplate('template_saved', $templateId);
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to plan meetings.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (MeetingRuleException | \InvalidArgumentException) {
            self::redirectTemplate('invalid', $templateId);
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

        $openId = isset($_GET['template']) ? absint($_GET['template']) : 0;
        $templateStep = isset($_GET['assoc_template_step']) ? sanitize_key((string) $_GET['assoc_template_step']) : '';
        $newMeeting = isset($_GET['assoc_meeting_step']) && sanitize_key((string) $_GET['assoc_meeting_step']) === 'new';
        $inTemplateFlow = $openId > 0 || $templateStep === 'choose' || $templateStep === 'items';
        $templateService = $canManage ? WordpressMeetings::templates() : null;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Meetings', 'foreningsplugin') . '</h1>';

        if (! $newMeeting && ! $inTemplateFlow) {
            echo '<p>' . esc_html__('A held meeting is not minutes. Notes are working material until minutes are created.', 'foreningsplugin') . '</p>';
        }

        self::notice();

        if ($newMeeting && $templateService instanceof MeetingTemplates) {
            self::newMeetingForm($types, $templateService);
            echo '</div>';

            return;
        }

        if ($inTemplateFlow && $templateService instanceof MeetingTemplates) {
            self::templateEditor($types, $templateService, $openId);
            echo '</div>';

            return;
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

        if ($templateService instanceof MeetingTemplates) {
            $createUrl = add_query_arg([
                'page' => 'foreningsplugin-meetings',
                'assoc_meeting_step' => 'new',
            ], admin_url('admin.php'));
            echo '<p><a class="button button-primary" href="' . esc_url($createUrl) . '">' . esc_html__('New meeting', 'foreningsplugin') . '</a></p>';
            self::templateEditor($types, $templateService, 0);
        }

        echo '</div>';
    }

    /**
     * @param array<int, MeetingType> $types
     */
    private static function newMeetingForm(array $types, MeetingTemplates $templates): void
    {
        $listUrl = add_query_arg(['page' => 'foreningsplugin-meetings'], admin_url('admin.php'));
        echo '<p><a href="' . esc_url($listUrl) . '">' . esc_html__('Meetings', 'foreningsplugin') . '</a></p>';
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
        echo '<p class="description">' . esc_html__('The list shows meeting templates the association has saved. Choosing one copies its headings into this meeting. A later change to the template does not change the meeting.', 'foreningsplugin') . '</p>';
        echo '<p><label>' . esc_html__('Template', 'foreningsplugin') . ' <select name="template_id">';
        echo '<option value="0">' . esc_html__('No template', 'foreningsplugin') . '</option>';

        foreach ($templates->all() as $template) {
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
    private static function templateEditor(array $types, MeetingTemplates $templates, int $openId): void
    {
        echo '<h2>' . esc_html__('Meeting templates', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('Create a meeting template from a starting point. Check the items to keep, then add headings and change their order. A meeting gets its own copy.', 'foreningsplugin') . '</p>';

        $open = null;

        foreach ($templates->all() as $template) {
            if ($template->id() === $openId) {
                $open = $template;
            }
        }

        $step = isset($_GET['assoc_template_step']) ? sanitize_key((string) $_GET['assoc_template_step']) : '';
        $starter = self::guideStarter();

        if ($open instanceof MeetingTemplate) {
            self::savedTemplateEditor($types, $templates, $open);
        } elseif ($step === 'items' && $starter !== null) {
            self::templatePoints($starter);

            return;
        } elseif ($step === 'choose' || $step === 'items') {
            self::chooseStarter($types);

            return;
        } else {
            $createUrl = add_query_arg([
                'page' => 'foreningsplugin-meetings',
                'assoc_template_step' => 'choose',
            ], admin_url('admin.php'));
            echo '<p><a class="button button-primary" href="' . esc_url($createUrl) . '">' . esc_html__('Create new template', 'foreningsplugin') . '</a></p>';
        }

        echo '<h3>' . esc_html__('Saved templates', 'foreningsplugin') . '</h3>';
        $saved = $templates->all();

        if ($saved === []) {
            echo '<p>' . esc_html__('No saved meeting templates yet.', 'foreningsplugin') . '</p>';

            return;
        }

        echo '<ul>';

        foreach ($saved as $template) {
            if ($template->id() === null) {
                continue;
            }

            $type = $types[$template->typeId()] ?? null;
            $label = ($type instanceof MeetingType ? self::typeLabel($type) . ': ' : '') . $template->name();
            $url = add_query_arg([
                'page' => 'foreningsplugin-meetings',
                'template' => (int) $template->id(),
            ], admin_url('admin.php'));
            echo '<li>' . esc_html($label) . ' <a href="' . esc_url($url) . '">' . esc_html__('Open template', 'foreningsplugin') . '</a></li>';
        }

        echo '</ul>';
    }

    /**
     * @param array<int, MeetingType> $types
     */
    private static function chooseStarter(array $types): void
    {
        $listUrl = add_query_arg(['page' => 'foreningsplugin-meetings'], admin_url('admin.php'));
        echo '<p><a href="' . esc_url($listUrl) . '">' . esc_html__('Meeting templates', 'foreningsplugin') . '</a></p>';
        echo '<h3>' . esc_html__('Create new template', 'foreningsplugin') . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_template_guide">';
        wp_nonce_field('assoc_template_guide');
        echo '<fieldset><legend>' . esc_html__('Choose a starting point', 'foreningsplugin') . '</legend>';

        foreach (MeetingStarterCatalog::all() as $starter) {
            echo '<p><label><input type="radio" name="starter" value="' . esc_attr($starter->key()) . '" required> ';
            echo esc_html(__($starter->label(), 'foreningsplugin')) . '</label></p>';
        }

        echo '</fieldset>';
        echo '<p class="description">' . esc_html__('The meeting type is used for the empty template. The other starting points already belong to a meeting type.', 'foreningsplugin') . '</p>';
        echo '<p><label>' . esc_html__('Type', 'foreningsplugin') . ' <select name="type_id">';
        echo '<option value="">' . esc_html__('Choose type', 'foreningsplugin') . '</option>';

        foreach ($types as $type) {
            echo '<option value="' . esc_attr((string) $type->id()) . '">' . esc_html(self::typeLabel($type)) . '</option>';
        }

        echo '</select></label></p>';
        submit_button(__('Next', 'foreningsplugin'));
        echo '</form>';
    }

    private static function templatePoints(\Foreningssystem\Application\Meeting\MeetingStarter $starter): void
    {
        $backUrl = add_query_arg([
            'page' => 'foreningsplugin-meetings',
            'assoc_template_step' => 'choose',
        ], admin_url('admin.php'));
        echo '<p><a href="' . esc_url($backUrl) . '">' . esc_html__('Choose a starting point', 'foreningsplugin') . '</a></p>';
        echo '<h3>' . esc_html(__($starter->label(), 'foreningsplugin')) . '</h3>';
        echo '<p>' . esc_html__('All items are checked. Uncheck the ones to leave out.', 'foreningsplugin') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_save_meeting_template">';
        echo '<input type="hidden" name="starter" value="' . esc_attr($starter->key()) . '">';
        wp_nonce_field('assoc_save_meeting_template');
        echo '<ol>';

        foreach ($starter->headings() as $index => $heading) {
            echo '<li><label><input type="checkbox" name="heading[]" value="' . esc_attr((string) $index) . '" checked> ';
            echo esc_html(__($heading, 'foreningsplugin')) . '</label></li>';
        }

        echo '</ol>';
        submit_button(__('Next', 'foreningsplugin'));
        echo '</form>';
    }

    private static function guideStarter(): ?\Foreningssystem\Application\Meeting\MeetingStarter
    {
        $key = isset($_GET['starter']) ? sanitize_key((string) $_GET['starter']) : '';
        $starter = MeetingStarterCatalog::find($key);

        if ($starter === null || $starter->headings() === []) {
            return null;
        }

        return $starter;
    }

    /**
     * @param array<int, MeetingType> $types
     */
    private static function savedTemplateEditor(array $types, MeetingTemplates $templates, MeetingTemplate $template): void
    {
        $templateId = (int) $template->id();
        $type = $types[$template->typeId()] ?? null;
        $listUrl = add_query_arg(['page' => 'foreningsplugin-meetings'], admin_url('admin.php'));
        echo '<p><a href="' . esc_url($listUrl) . '">' . esc_html__('Meeting templates', 'foreningsplugin') . '</a></p>';
        echo '<h3>' . esc_html(($type instanceof MeetingType ? self::typeLabel($type) . ': ' : '') . $template->name()) . '</h3>';
        echo '<p>' . esc_html__('Add headings and change their order. Rename the template if the association uses its own name.', 'foreningsplugin') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_rename_meeting_template">';
        echo '<input type="hidden" name="template_id" value="' . esc_attr((string) $templateId) . '">';
        wp_nonce_field('assoc_rename_meeting_template');
        echo '<p><label>' . esc_html__('Name', 'foreningsplugin') . ' ';
        echo '<input class="regular-text" type="text" name="name" value="' . esc_attr($template->name()) . '" required>';
        echo '</label> ';
        submit_button(__('Save template', 'foreningsplugin'), 'secondary', 'submit', false);
        echo '</p></form>';
        echo '<ol>';

        foreach ($templates->headings($templateId) as $heading) {
            $itemId = (int) $heading->id();
            echo '<li>';
            self::headingForm('assoc_rename_template_heading', $templateId, $itemId, '');
            echo '<input class="regular-text" type="text" name="title" value="' . esc_attr($heading->title()) . '" required> ';
            submit_button(__('Save heading', 'foreningsplugin'), 'secondary', 'submit', false);
            echo '</form> ';
            self::headingForm('assoc_move_template_heading', $templateId, $itemId, 'up');
            submit_button(__('Move up', 'foreningsplugin'), 'secondary', 'submit', false);
            echo '</form> ';
            self::headingForm('assoc_move_template_heading', $templateId, $itemId, 'down');
            submit_button(__('Move down', 'foreningsplugin'), 'secondary', 'submit', false);
            echo '</form> ';
            self::headingForm('assoc_remove_template_heading', $templateId, $itemId, '');
            submit_button(__('Remove heading', 'foreningsplugin'), 'delete', 'submit', false);
            echo '</form>';
            echo '</li>';
        }

        echo '</ol>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_add_template_heading">';
        echo '<input type="hidden" name="template_id" value="' . esc_attr((string) $templateId) . '">';
        wp_nonce_field('assoc_add_template_heading');
        self::field('title', __('Heading', 'foreningsplugin'), 'text', true);
        submit_button(__('Add heading', 'foreningsplugin'), 'secondary');
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_remove_meeting_template">';
        echo '<input type="hidden" name="template_id" value="' . esc_attr((string) $templateId) . '">';
        wp_nonce_field('assoc_remove_meeting_template');
        submit_button(__('Remove template', 'foreningsplugin'), 'delete');
        echo '</form>';
    }

    private static function headingForm(string $action, int $templateId, int $itemId, string $direction): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:0.5em">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="template_id" value="' . esc_attr((string) $templateId) . '">';
        echo '<input type="hidden" name="item_id" value="' . esc_attr((string) $itemId) . '">';

        if ($direction !== '') {
            echo '<input type="hidden" name="direction" value="' . esc_attr($direction) . '">';
        }

        wp_nonce_field($action);
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

    private static function redirectNewMeeting(string $notice): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => 'foreningsplugin-meetings',
            'assoc_meeting_step' => 'new',
            'assoc_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    private static function redirectTemplate(string $notice, int $templateId): void
    {
        $args = [
            'page' => 'foreningsplugin-meetings',
            'assoc_notice' => $notice,
        ];

        if ($templateId > 0) {
            $args['template'] = $templateId;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private static function redirectGuide(string $step, string $starter = '', bool $invalid = false): void
    {
        $args = [
            'page' => 'foreningsplugin-meetings',
            'assoc_template_step' => $step,
        ];

        if ($starter !== '') {
            $args['starter'] = $starter;
        }

        if ($invalid) {
            $args['assoc_notice'] = 'invalid';
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * @return list<string>
     */
    private static function selectedHeadings(\Foreningssystem\Application\Meeting\MeetingStarter $starter): array
    {
        $posted = $_POST['heading'] ?? [];
        $wanted = [];

        if (is_array($posted)) {
            foreach ($posted as $value) {
                if (is_numeric($value)) {
                    $wanted[(int) $value] = true;
                }
            }
        }

        $headings = [];

        foreach ($starter->headings() as $index => $heading) {
            if (isset($wanted[$index])) {
                $headings[] = __($heading, 'foreningsplugin');
            }
        }

        return $headings;
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
        return MeetingLabels::type($type);
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
