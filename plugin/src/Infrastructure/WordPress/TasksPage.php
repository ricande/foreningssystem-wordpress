<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\Task\AgendaPresentation;
use Foreningssystem\Application\Task\AssigneePresentation;
use Foreningssystem\Application\Task\TaskRegisterQuery;
use Foreningssystem\Application\Task\TaskRegisterRow;
use Foreningssystem\Application\Task\TaskRegisterSnapshot;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\ActionStatus;
use Foreningssystem\Domain\Membership\AssociationDate;
use InvalidArgumentException;

final class TasksPage
{
    public static function render(): void
    {
        if (! current_user_can(Capabilities::VIEW_INTERNAL_MEETINGS)) {
            wp_die(esc_html__('You do not have permission to view tasks.', 'foreningsplugin'), '', ['response' => 403]);
        }

        $query = self::queryFromGet();

        try {
            $snapshot = WordpressMeetings::tasks()->snapshot(AssociationDate::fromIso(wp_date('Y-m-d')), $query);
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to view tasks.', 'foreningsplugin'), '', ['response' => 403]);
        }

        $canRecord = current_user_can(Capabilities::RECORD_MEETING);
        $total = $snapshot->openCount + $snapshot->doneCount;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Tasks', 'foreningsplugin') . '</h1>';
        echo '<p>' . esc_html__('Tasks are recorded from meetings.', 'foreningsplugin') . '</p>';
        self::notice();

        if ($total === 0) {
            echo '<p>' . esc_html__('No tasks have been recorded yet.', 'foreningsplugin') . '</p>';
            echo '<p>' . esc_html__('Tasks are added from a meeting workspace.', 'foreningsplugin') . '</p>';

            if ($canRecord) {
                echo '<p><a href="' . esc_url(admin_url('admin.php?page=foreningsplugin-meetings')) . '">' . esc_html__('Open meetings', 'foreningsplugin') . '</a></p>';
            }

            echo '</div>';

            return;
        }

        self::counts($snapshot);
        self::filters($snapshot);

        if ($snapshot->matched === 0) {
            echo '<p>' . esc_html__('No tasks match these filters.', 'foreningsplugin') . '</p>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=foreningsplugin-tasks')) . '">' . esc_html__('Clear filters', 'foreningsplugin') . '</a></p>';
            echo '</div>';

            return;
        }

        echo '<style>.assoc-task{margin:0 0 1.5em;padding:0 0 1.5em;border-bottom:1px solid #dcdcde;max-width:70em}.assoc-task-wording{white-space:pre-wrap}</style>';

        foreach ($snapshot->rows as $row) {
            self::task($row, $snapshot, $canRecord);
        }

        self::pagination($snapshot);
        echo '</div>';
    }

    public static function setStatus(): void
    {
        if (! current_user_can(Capabilities::RECORD_MEETING)) {
            wp_die(esc_html__('You do not have permission to record notes, decisions, or tasks.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer('assoc_set_global_task_status');
        $actionItemId = absint(wp_unslash($_POST['action_item_id'] ?? 0));
        $requested = sanitize_text_field(wp_unslash((string) ($_POST['status'] ?? '')));

        try {
            if ($actionItemId < 1) {
                throw new InvalidArgumentException('A task needs a saved id.');
            }

            $status = TaskRegisterQuery::statusChange($requested);
            WordpressMeetings::record()->setActionStatus($actionItemId, $status, null);
            $notice = $status === ActionStatus::Done ? 'task_done' : 'task_open';
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to record notes, decisions, or tasks.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (InvalidArgumentException | \ValueError | \RuntimeException) {
            $notice = 'invalid';
        }

        wp_safe_redirect(add_query_arg(self::returnArgs($notice), admin_url('admin.php')));
        exit;
    }

    private static function queryFromGet(): TaskRegisterQuery
    {
        return TaskRegisterQuery::normalize(
            isset($_GET['status']) ? sanitize_text_field(wp_unslash((string) $_GET['status'])) : null,
            isset($_GET['assignee']) ? sanitize_text_field(wp_unslash((string) $_GET['assignee'])) : null,
            isset($_GET['urgency']) ? sanitize_text_field(wp_unslash((string) $_GET['urgency'])) : null,
            isset($_GET['paged']) ? absint(wp_unslash($_GET['paged'])) : 1
        );
    }

    /**
     * @return array<string, int|string>
     */
    private static function returnArgs(string $notice): array
    {
        $query = TaskRegisterQuery::normalize(
            isset($_POST['status_filter']) ? sanitize_text_field(wp_unslash((string) $_POST['status_filter'])) : null,
            isset($_POST['assignee']) ? sanitize_text_field(wp_unslash((string) $_POST['assignee'])) : null,
            isset($_POST['urgency']) ? sanitize_text_field(wp_unslash((string) $_POST['urgency'])) : null,
            isset($_POST['paged']) ? absint(wp_unslash($_POST['paged'])) : 1
        );

        $args = [
            'page' => 'foreningsplugin-tasks',
            'status' => $query->status,
            'assignee' => $query->assignee,
            'urgency' => $query->urgency,
            'assoc_notice' => $notice,
        ];

        if ($query->page > 1) {
            $args['paged'] = $query->page;
        }

        return $args;
    }

    private static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key(wp_unslash((string) $_GET['assoc_notice'])) : '';
        $message = match ($notice) {
            'task_done' => __('Task was marked done.', 'foreningsplugin'),
            'task_open' => __('Task was reopened.', 'foreningsplugin'),
            'invalid' => __('The task could not be updated.', 'foreningsplugin'),
            default => '',
        };

        if ($message === '') {
            return;
        }

        $class = $notice === 'invalid' ? 'notice notice-error' : 'notice notice-success';
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
    }

    private static function counts(TaskRegisterSnapshot $snapshot): void
    {
        echo '<ul>';
        echo '<li><a href="' . esc_url(self::filterUrl(TaskRegisterQuery::OPEN, TaskRegisterQuery::ALL, TaskRegisterQuery::ALL)) . '">' . esc_html(sprintf(
            /* translators: %d: number of open tasks */
            __('Open: %d', 'foreningsplugin'),
            $snapshot->openCount
        )) . '</a></li>';
        echo '<li><a href="' . esc_url(self::filterUrl(TaskRegisterQuery::OPEN, TaskRegisterQuery::ALL, TaskRegisterQuery::OVERDUE)) . '">' . esc_html(sprintf(
            /* translators: %d: number of open tasks past their due date */
            __('Overdue: %d', 'foreningsplugin'),
            $snapshot->overdueCount
        )) . '</a></li>';
        echo '<li><a href="' . esc_url(self::filterUrl(TaskRegisterQuery::DONE, TaskRegisterQuery::ALL, TaskRegisterQuery::ALL)) . '">' . esc_html(sprintf(
            /* translators: %d: number of completed tasks */
            __('Done: %d', 'foreningsplugin'),
            $snapshot->doneCount
        )) . '</a></li>';
        echo '</ul>';
    }

    private static function filters(TaskRegisterSnapshot $snapshot): void
    {
        $query = $snapshot->query;
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="foreningsplugin-tasks" />';
        echo '<label for="assoc-task-status">' . esc_html__('Status', 'foreningsplugin') . '</label> ';
        echo '<select id="assoc-task-status" name="status">';
        self::option(TaskRegisterQuery::OPEN, __('Open', 'foreningsplugin'), $query->status);
        self::option(TaskRegisterQuery::DONE, __('Done', 'foreningsplugin'), $query->status);
        self::option(TaskRegisterQuery::ALL, __('All', 'foreningsplugin'), $query->status);
        echo '</select> ';
        echo '<label for="assoc-task-assignee">' . esc_html__('Assignee', 'foreningsplugin') . '</label> ';
        echo '<select id="assoc-task-assignee" name="assignee">';
        self::option(TaskRegisterQuery::ALL, __('All', 'foreningsplugin'), $query->assignee);
        self::option(TaskRegisterQuery::UNASSIGNED, __('Unassigned', 'foreningsplugin'), $query->assignee);

        foreach ($snapshot->assigneeChoices as $choice) {
            self::option((string) $choice['id'], $choice['name'], $query->assignee);
        }

        echo '</select> ';
        echo '<label for="assoc-task-urgency">' . esc_html__('Urgency', 'foreningsplugin') . '</label> ';
        echo '<select id="assoc-task-urgency" name="urgency">';
        self::option(TaskRegisterQuery::ALL, __('All', 'foreningsplugin'), $query->urgency);
        self::option(TaskRegisterQuery::OVERDUE, __('Overdue', 'foreningsplugin'), $query->urgency);
        echo '</select> ';
        echo '<button type="submit" class="button">' . esc_html__('Filter', 'foreningsplugin') . '</button>';
        echo '</form>';
    }

    private static function option(string $value, string $label, string $selected): void
    {
        echo '<option value="' . esc_attr($value) . '"' . ($value === $selected ? ' selected="selected"' : '') . '>' . esc_html($label) . '</option>';
    }

    private static function task(TaskRegisterRow $row, TaskRegisterSnapshot $snapshot, bool $canRecord): void
    {
        echo '<article class="assoc-task">';
        echo '<h2 class="assoc-task-wording">' . esc_html($row->task) . '</h2>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: open or done */
            __('Status: %s', 'foreningsplugin'),
            $row->status === ActionStatus::Open ? __('Open', 'foreningsplugin') : __('Done', 'foreningsplugin')
        )) . '</p>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: assignee name, or a state when no current person can be shown */
            __('Assignee: %s', 'foreningsplugin'),
            self::assigneeLabel($row)
        )) . '</p>';
        echo '<p>' . esc_html(__('Due date', 'foreningsplugin') . ': ' . ($row->dueOn ?? __('No due date', 'foreningsplugin'))) . '</p>';

        if ($row->overdue) {
            echo '<p>' . esc_html__('Overdue', 'foreningsplugin') . '</p>';
        }

        echo '<p>' . esc_html__('Source', 'foreningsplugin') . '</p>';

        if (! $row->meetingAvailable || $row->meetingTitle === null || $row->meetingDate === null || $row->meetingStatus === null) {
            echo '<p>' . esc_html__('Meeting unavailable', 'foreningsplugin') . '</p>';
        } else {
            echo '<p>' . esc_html($row->meetingTitle . ' — ' . $row->meetingDate . ' — ' . MeetingLabels::status($row->meetingStatus)) . '</p>';
        }

        echo '<p>' . esc_html(self::agendaLabel($row)) . '</p>';

        if ($row->meetingAvailable) {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=foreningsplugin-meetings&meeting=' . $row->meetingId)) . '">' . esc_html__('Open meeting', 'foreningsplugin') . '</a></p>';
        }

        if ($canRecord) {
            $next = $row->status === ActionStatus::Open ? ActionStatus::Done : ActionStatus::Open;
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('assoc_set_global_task_status');
            echo '<input type="hidden" name="action" value="assoc_set_global_task_status" />';
            echo '<input type="hidden" name="action_item_id" value="' . esc_attr((string) $row->actionItemId) . '" />';
            echo '<input type="hidden" name="status" value="' . esc_attr($next->value) . '" />';
            echo '<input type="hidden" name="status_filter" value="' . esc_attr($snapshot->query->status) . '" />';
            echo '<input type="hidden" name="assignee" value="' . esc_attr($snapshot->query->assignee) . '" />';
            echo '<input type="hidden" name="urgency" value="' . esc_attr($snapshot->query->urgency) . '" />';
            echo '<input type="hidden" name="paged" value="' . esc_attr((string) $snapshot->page) . '" />';
            $label = $next === ActionStatus::Done
                ? __('Mark task done', 'foreningsplugin')
                : __('Reopen task', 'foreningsplugin');
            echo '<button type="submit" class="button">' . esc_html($label) . '</button>';
            echo '</form>';
        }

        echo '</article>';
    }

    private static function assigneeLabel(TaskRegisterRow $row): string
    {
        return match ($row->assigneeState) {
            AssigneePresentation::Assigned => (string) $row->assigneeName,
            AssigneePresentation::Unassigned => __('Unassigned', 'foreningsplugin'),
            AssigneePresentation::Unavailable => __('Person unavailable', 'foreningsplugin'),
        };
    }

    private static function agendaLabel(TaskRegisterRow $row): string
    {
        return match ($row->agendaState) {
            AgendaPresentation::None => __('Meeting-level task', 'foreningsplugin'),
            AgendaPresentation::Unavailable => __('Agenda item unavailable', 'foreningsplugin'),
            AgendaPresentation::Item => trim(($row->agendaNumber ?? '') . ' ' . ($row->agendaTitle ?? '')),
        };
    }

    private static function pagination(TaskRegisterSnapshot $snapshot): void
    {
        if ($snapshot->pages < 2) {
            return;
        }

        echo '<nav class="assoc-task-pages" aria-label="' . esc_attr__('Tasks', 'foreningsplugin') . '"><p>';
        echo esc_html(sprintf(
            /* translators: 1: current page, 2: number of pages */
            __('Page %1$d of %2$d', 'foreningsplugin'),
            $snapshot->page,
            $snapshot->pages
        ));

        if ($snapshot->page > 1) {
            echo ' <a href="' . esc_url(self::pageUrl($snapshot, $snapshot->page - 1)) . '">' . esc_html__('Previous', 'foreningsplugin') . '</a>';
        }

        if ($snapshot->page < $snapshot->pages) {
            echo ' <a href="' . esc_url(self::pageUrl($snapshot, $snapshot->page + 1)) . '">' . esc_html__('Next', 'foreningsplugin') . '</a>';
        }

        echo '</p></nav>';
    }

    private static function pageUrl(TaskRegisterSnapshot $snapshot, int $page): string
    {
        return self::filterUrl($snapshot->query->status, $snapshot->query->assignee, $snapshot->query->urgency, $page);
    }

    private static function filterUrl(string $status, string $assignee, string $urgency, int $page = 1): string
    {
        $args = [
            'page' => 'foreningsplugin-tasks',
            'status' => $status,
            'assignee' => $assignee,
            'urgency' => $urgency,
        ];

        if ($page > 1) {
            $args['paged'] = $page;
        }

        return add_query_arg($args, admin_url('admin.php'));
    }
}
