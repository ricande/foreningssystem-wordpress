<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Decision\AgendaPresentation;
use Foreningssystem\Application\Decision\DecisionRegisterQuery;
use Foreningssystem\Application\Decision\DecisionRegisterRow;
use Foreningssystem\Application\Decision\DecisionRegisterSnapshot;
use Foreningssystem\Application\Decision\ResponsiblePresentation;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\DecisionFollowUp;
use Foreningssystem\Domain\Membership\AssociationDate;
use InvalidArgumentException;

final class DecisionsPage
{
    public static function render(): void
    {
        if (! current_user_can(Capabilities::VIEW_INTERNAL_MEETINGS)) {
            wp_die(esc_html__('You do not have permission to view decisions.', 'foreningsplugin'), '', ['response' => 403]);
        }

        $query = self::queryFromGet();

        try {
            $snapshot = WordpressMeetings::register()->snapshot(AssociationDate::fromIso(wp_date('Y-m-d')), $query);
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to view decisions.', 'foreningsplugin'), '', ['response' => 403]);
        }

        $canRecord = current_user_can(Capabilities::RECORD_MEETING);
        $total = $snapshot->openCount + $snapshot->doneCount;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Decisions', 'foreningsplugin') . '</h1>';
        echo '<p>' . esc_html__('Decisions are recorded in meetings. Follow-up status can change without changing finalized minutes.', 'foreningsplugin') . '</p>';
        self::notice();

        if ($total === 0) {
            echo '<p>' . esc_html__('No decisions have been recorded yet.', 'foreningsplugin') . '</p>';
            echo '<p>' . esc_html__('Decisions are added from a meeting workspace.', 'foreningsplugin') . '</p>';

            if ($canRecord) {
                echo '<p><a href="' . esc_url(admin_url('admin.php?page=foreningsplugin-meetings')) . '">' . esc_html__('Open meetings', 'foreningsplugin') . '</a></p>';
            }

            echo '</div>';

            return;
        }

        self::counts($snapshot);
        self::filters($snapshot);

        if ($snapshot->matched === 0) {
            echo '<p>' . esc_html__('No decisions match these filters.', 'foreningsplugin') . '</p>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=foreningsplugin-decisions')) . '">' . esc_html__('Clear filters', 'foreningsplugin') . '</a></p>';
            echo '</div>';

            return;
        }

        echo '<style>.assoc-decision{margin:0 0 1.5em;padding:0 0 1.5em;border-bottom:1px solid #dcdcde;max-width:70em}.assoc-decision-wording{white-space:pre-wrap}</style>';

        foreach ($snapshot->rows as $row) {
            self::decision($row, $snapshot, $canRecord);
        }

        self::pagination($snapshot);
        echo '</div>';
    }

    public static function setFollowUp(): void
    {
        if (! current_user_can(Capabilities::RECORD_MEETING)) {
            wp_die(esc_html__('You do not have permission to record notes, decisions, or tasks.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer('assoc_set_global_decision_follow_up');
        $decisionId = absint(wp_unslash($_POST['decision_id'] ?? 0));
        $requested = sanitize_text_field(wp_unslash((string) ($_POST['follow_up'] ?? '')));

        try {
            if ($decisionId < 1) {
                throw new InvalidArgumentException('A decision needs a saved id.');
            }

            WordpressMeetings::record()->setFollowUp($decisionId, DecisionRegisterQuery::followUpChange($requested), null);
            $notice = DecisionRegisterQuery::followUpChange($requested) === DecisionFollowUp::Done ? 'follow_up_done' : 'follow_up_open';
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to record notes, decisions, or tasks.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (InvalidArgumentException | \ValueError | \RuntimeException) {
            $notice = 'invalid';
        }

        wp_safe_redirect(add_query_arg(self::returnArgs($notice), admin_url('admin.php')));
        exit;
    }

    private static function queryFromGet(): DecisionRegisterQuery
    {
        return DecisionRegisterQuery::normalize(
            isset($_GET['status']) ? sanitize_text_field(wp_unslash((string) $_GET['status'])) : null,
            isset($_GET['responsible']) ? sanitize_text_field(wp_unslash((string) $_GET['responsible'])) : null,
            isset($_GET['urgency']) ? sanitize_text_field(wp_unslash((string) $_GET['urgency'])) : null,
            isset($_GET['paged']) ? absint(wp_unslash($_GET['paged'])) : 1
        );
    }

    /**
     * @return array<string, int|string>
     */
    private static function returnArgs(string $notice): array
    {
        $query = DecisionRegisterQuery::normalize(
            isset($_POST['status']) ? sanitize_text_field(wp_unslash((string) $_POST['status'])) : null,
            isset($_POST['responsible']) ? sanitize_text_field(wp_unslash((string) $_POST['responsible'])) : null,
            isset($_POST['urgency']) ? sanitize_text_field(wp_unslash((string) $_POST['urgency'])) : null,
            isset($_POST['paged']) ? absint(wp_unslash($_POST['paged'])) : 1
        );

        $args = [
            'page' => 'foreningsplugin-decisions',
            'status' => $query->followUp,
            'responsible' => $query->responsible,
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
            'follow_up_done' => __('Follow-up was marked done.', 'foreningsplugin'),
            'follow_up_open' => __('Follow-up was reopened.', 'foreningsplugin'),
            'invalid' => __('The decision could not be updated.', 'foreningsplugin'),
            default => '',
        };

        if ($message === '') {
            return;
        }

        $class = $notice === 'invalid' ? 'notice notice-error' : 'notice notice-success';
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
    }

    private static function counts(DecisionRegisterSnapshot $snapshot): void
    {
        echo '<ul>';
        echo '<li><a href="' . esc_url(self::filterUrl(DecisionRegisterQuery::OPEN, DecisionRegisterQuery::ALL, DecisionRegisterQuery::ALL)) . '">' . esc_html(sprintf(
            /* translators: %d: number of decisions with open follow-up */
            __('Open: %d', 'foreningsplugin'),
            $snapshot->openCount
        )) . '</a></li>';
        echo '<li><a href="' . esc_url(self::filterUrl(DecisionRegisterQuery::OPEN, DecisionRegisterQuery::ALL, DecisionRegisterQuery::OVERDUE)) . '">' . esc_html(sprintf(
            /* translators: %d: number of open decisions past their deadline */
            __('Overdue: %d', 'foreningsplugin'),
            $snapshot->overdueCount
        )) . '</a></li>';
        echo '<li><a href="' . esc_url(self::filterUrl(DecisionRegisterQuery::DONE, DecisionRegisterQuery::ALL, DecisionRegisterQuery::ALL)) . '">' . esc_html(sprintf(
            /* translators: %d: number of decisions with completed follow-up */
            __('Done: %d', 'foreningsplugin'),
            $snapshot->doneCount
        )) . '</a></li>';
        echo '</ul>';
    }

    private static function filters(DecisionRegisterSnapshot $snapshot): void
    {
        $query = $snapshot->query;
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="foreningsplugin-decisions" />';
        echo '<label for="assoc-decision-status">' . esc_html__('Follow-up', 'foreningsplugin') . '</label> ';
        echo '<select id="assoc-decision-status" name="status">';
        self::option(DecisionRegisterQuery::OPEN, __('Open', 'foreningsplugin'), $query->followUp);
        self::option(DecisionRegisterQuery::DONE, __('Done', 'foreningsplugin'), $query->followUp);
        self::option(DecisionRegisterQuery::ALL, __('All', 'foreningsplugin'), $query->followUp);
        echo '</select> ';
        echo '<label for="assoc-decision-responsible">' . esc_html__('Responsible', 'foreningsplugin') . '</label> ';
        echo '<select id="assoc-decision-responsible" name="responsible">';
        self::option(DecisionRegisterQuery::ALL, __('All', 'foreningsplugin'), $query->responsible);
        self::option(DecisionRegisterQuery::UNASSIGNED, __('Unassigned', 'foreningsplugin'), $query->responsible);

        foreach ($snapshot->responsibleChoices as $choice) {
            self::option((string) $choice['id'], $choice['name'], $query->responsible);
        }

        echo '</select> ';
        echo '<label for="assoc-decision-urgency">' . esc_html__('Urgency', 'foreningsplugin') . '</label> ';
        echo '<select id="assoc-decision-urgency" name="urgency">';
        self::option(DecisionRegisterQuery::ALL, __('All', 'foreningsplugin'), $query->urgency);
        self::option(DecisionRegisterQuery::OVERDUE, __('Overdue', 'foreningsplugin'), $query->urgency);
        echo '</select> ';
        echo '<button type="submit" class="button">' . esc_html__('Filter', 'foreningsplugin') . '</button>';
        echo '</form>';
    }

    private static function option(string $value, string $label, string $selected): void
    {
        echo '<option value="' . esc_attr($value) . '"' . ($value === $selected ? ' selected="selected"' : '') . '>' . esc_html($label) . '</option>';
    }

    private static function decision(DecisionRegisterRow $row, DecisionRegisterSnapshot $snapshot, bool $canRecord): void
    {
        echo '<article class="assoc-decision">';
        echo '<p class="assoc-decision-wording">' . esc_html($row->wording) . '</p>';
        echo '<p>' . esc_html($row->followUp === DecisionFollowUp::Open
            ? __('Follow-up: Open', 'foreningsplugin')
            : __('Follow-up: Done', 'foreningsplugin')) . '</p>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: responsible person, or a state when no current person can be shown */
            __('Responsible: %s', 'foreningsplugin'),
            self::responsibleLabel($row)
        )) . '</p>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: deadline date or a note that there is none */
            __('Due date: %s', 'foreningsplugin'),
            $row->deadline ?? __('No deadline', 'foreningsplugin')
        )) . '</p>';

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
            $next = $row->followUp === DecisionFollowUp::Open ? DecisionFollowUp::Done : DecisionFollowUp::Open;
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('assoc_set_global_decision_follow_up');
            echo '<input type="hidden" name="action" value="assoc_set_global_decision_follow_up" />';
            echo '<input type="hidden" name="decision_id" value="' . esc_attr((string) $row->decisionId) . '" />';
            echo '<input type="hidden" name="follow_up" value="' . esc_attr($next->value) . '" />';
            echo '<input type="hidden" name="status" value="' . esc_attr($snapshot->query->followUp) . '" />';
            echo '<input type="hidden" name="responsible" value="' . esc_attr($snapshot->query->responsible) . '" />';
            echo '<input type="hidden" name="urgency" value="' . esc_attr($snapshot->query->urgency) . '" />';
            echo '<input type="hidden" name="paged" value="' . esc_attr((string) $snapshot->page) . '" />';
            $label = $next === DecisionFollowUp::Done
                ? __('Mark follow-up done', 'foreningsplugin')
                : __('Reopen follow-up', 'foreningsplugin');
            echo '<button type="submit" class="button">' . esc_html($label) . '</button>';
            echo '</form>';
        }

        echo '</article>';
    }

    private static function responsibleLabel(DecisionRegisterRow $row): string
    {
        return match ($row->responsibleState) {
            ResponsiblePresentation::Assigned => (string) $row->responsibleName,
            ResponsiblePresentation::Unassigned => __('Unassigned', 'foreningsplugin'),
            ResponsiblePresentation::Unavailable => __('Person unavailable', 'foreningsplugin'),
        };
    }

    private static function agendaLabel(DecisionRegisterRow $row): string
    {
        return match ($row->agendaState) {
            AgendaPresentation::None => __('Meeting-level decision', 'foreningsplugin'),
            AgendaPresentation::Unavailable => __('Agenda item unavailable', 'foreningsplugin'),
            AgendaPresentation::Item => trim(($row->agendaNumber ?? '') . ' ' . ($row->agendaTitle ?? '')),
        };
    }

    private static function pagination(DecisionRegisterSnapshot $snapshot): void
    {
        if ($snapshot->pages < 2) {
            return;
        }

        echo '<nav class="assoc-decision-pages" aria-label="' . esc_attr__('Decisions', 'foreningsplugin') . '"><p>';
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

    private static function pageUrl(DecisionRegisterSnapshot $snapshot, int $page): string
    {
        return self::filterUrl($snapshot->query->followUp, $snapshot->query->responsible, $snapshot->query->urgency, $page);
    }

    private static function filterUrl(string $followUp, string $responsible, string $urgency, int $page = 1): string
    {
        $args = [
            'page' => 'foreningsplugin-decisions',
            'status' => $followUp,
            'responsible' => $responsible,
            'urgency' => $urgency,
        ];

        if ($page > 1) {
            $args['paged'] = $page;
        }

        return add_query_arg($args, admin_url('admin.php'));
    }
}
