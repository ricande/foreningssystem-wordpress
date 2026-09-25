<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Association\DashboardOverview;
use Foreningssystem\Application\Association\DashboardSnapshot;
use Foreningssystem\Application\Association\DashboardSources;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Document\DocumentVisibility;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Membership\AssociationDate;

final class AssociationOverviewPage
{
    public static function render(): void
    {
        if (! current_user_can(Capabilities::ACCESS_ASSOCIATION)) {
            wp_die(esc_html__('You do not have permission to view the association.', 'foreningsplugin'), '', ['response' => 403]);
        }

        if (! self::hasOperationalOverview()) {
            self::renderSettingsHome();

            return;
        }

        $snapshot = self::snapshot();
        $title = $snapshot->associationName !== ''
            ? $snapshot->associationName
            : __('Association', 'foreningsplugin');

        echo '<div class="wrap assoc-dashboard">';
        echo '<style>
            .assoc-dashboard-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr));gap:1rem;margin:1rem 0}
            .assoc-dashboard-card{background:#fff;border:1px solid #c3c4c7;padding:0.75rem 1rem}
            .assoc-dashboard-card h3{margin-top:0}
            .assoc-dashboard-attention{border-left:4px solid #2271b1}
        </style>';
        echo '<h1>' . esc_html($title) . '</h1>';

        if ($snapshot->needsProfile) {
            echo '<p>' . esc_html__('Add the association name and details.', 'foreningsplugin') . ' ';
            echo '<a href="' . esc_url(self::pageUrl('foreningsplugin-profile')) . '">' . esc_html__('Open profile', 'foreningsplugin') . '</a></p>';
        }

        if ($snapshot->isEmpty) {
            if ($snapshot->setup !== []) {
                self::renderSetup($snapshot);
            } else {
                echo '<p>' . esc_html__('The association has no records yet.', 'foreningsplugin') . '</p>';
            }

            echo '</div>';

            return;
        }

        self::renderAttention($snapshot);
        self::renderGlance($snapshot);
        self::renderMeetings($snapshot);
        self::renderBoard($snapshot);
        self::renderWork($snapshot);
        self::renderDocuments($snapshot);
        echo '</div>';
    }

    private static function hasOperationalOverview(): bool
    {
        return current_user_can(Capabilities::VIEW_MEMBERS)
            || current_user_can(Capabilities::VIEW_INTERNAL_MEETINGS)
            || current_user_can(Capabilities::VIEW_BOARD_DOCUMENTS)
            || current_user_can(Capabilities::MANAGE_DOCUMENTS);
    }

    private static function renderSettingsHome(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Association', 'foreningsplugin') . '</h1>';

        if (current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            echo '<p>' . esc_html__('You have access to association settings.', 'foreningsplugin') . '</p>';
            echo '<p><a class="button button-primary" href="' . esc_url(self::pageUrl('foreningsplugin-settings')) . '">' . esc_html__('Open Settings', 'foreningsplugin') . '</a></p>';
        }

        echo '</div>';
    }

    private static function snapshot(): DashboardSnapshot
    {
        $today = AssociationDate::fromIso(wp_date('Y-m-d'));
        $now = MeetingMoment::fromLocal(wp_date('Y-m-d H:i:s'));
        $canMembers = current_user_can(Capabilities::VIEW_MEMBERS);
        $canMeetings = current_user_can(Capabilities::VIEW_INTERNAL_MEETINGS);
        $canDocuments = current_user_can(Capabilities::VIEW_BOARD_DOCUMENTS) || current_user_can(Capabilities::MANAGE_DOCUMENTS);
        $documents = [];

        if ($canDocuments) {
            foreach (WordpressDocuments::archive()->officerList() as $document) {
                $documents[] = [
                    'id' => $document->id(),
                    'title' => $document->title(),
                    'visibility' => $document->visibility()->value,
                ];
            }
        }

        $decisionRows = $canMeetings ? WordpressMeetings::record()->openDecisions() : [];
        $actionRows = $canMeetings ? WordpressMeetings::record()->openActions() : [];

        return (new DashboardOverview())->snapshot(new DashboardSources(
            $canMembers,
            $canMeetings,
            current_user_can(Capabilities::MANAGE_MEETINGS),
            $canDocuments,
            current_user_can(Capabilities::MANAGE_ASSOCIATION),
            current_user_can(Capabilities::EDIT_MEMBERS),
            current_user_can(Capabilities::MANAGE_BOARD),
            current_user_can(Capabilities::MANAGE_DOCUMENTS),
            WordpressAssociationProfile::load()->name(),
            $today,
            $now,
            $canMembers ? WordpressPeople::service()->activeMemberCount($today) : 0,
            $canMembers ? WordpressPeople::service()->activeMembershipCount($today) : 0,
            $canMembers ? WordpressBoard::service()->currentCount($today) : 0,
            $canMembers ? WordpressBoard::directory()->seats($today) : [],
            $canMeetings ? WordpressMeetings::service()->listMeetings() : [],
            $canMeetings ? WordpressMeetings::minutes()->revisions() : [],
            count($decisionRows),
            $actionRows,
            $documents,
        ));
    }

    private static function renderSetup(DashboardSnapshot $snapshot): void
    {
        echo '<h2>' . esc_html__('Set up your association', 'foreningsplugin') . '</h2>';
        echo '<ol>';

        foreach ($snapshot->setup as $step) {
            echo '<li>';

            if ($step === 'members') {
                echo '<a href="' . esc_url(self::pageUrl('foreningsplugin-members')) . '">' . esc_html__('Add members', 'foreningsplugin') . '</a>';
            } elseif ($step === 'board') {
                echo '<a href="' . esc_url(self::pageUrl('foreningsplugin-board')) . '">' . esc_html__('Record the board', 'foreningsplugin') . '</a>';
            } elseif ($step === 'meeting') {
                echo '<a href="' . esc_url(self::pageUrl('foreningsplugin-meetings')) . '">' . esc_html__('Plan the first meeting', 'foreningsplugin') . '</a>';
            } else {
                echo '<a href="' . esc_url(self::pageUrl('foreningsplugin-documents')) . '">' . esc_html__('Add important documents', 'foreningsplugin') . '</a>';
            }

            echo '</li>';
        }

        echo '</ol>';
    }

    private static function renderAttention(DashboardSnapshot $snapshot): void
    {
        echo '<h2>' . esc_html__('Needs attention', 'foreningsplugin') . '</h2>';

        if ($snapshot->attention === []) {
            echo '<p>' . esc_html__('Nothing needs attention right now.', 'foreningsplugin') . '</p>';

            return;
        }

        echo '<ul>';

        foreach ($snapshot->attention as $item) {
            echo '<li class="assoc-dashboard-attention">';

            if ($item['kind'] === 'in_progress') {
                echo esc_html__('Meeting in progress', 'foreningsplugin') . ': ' . esc_html($item['title']);
                echo ' <span>' . esc_html($item['detail']) . '</span> ';
                echo '<a href="' . esc_url(self::meetingUrl((int) $item['meetingId'])) . '">' . esc_html__('Continue meeting', 'foreningsplugin') . '</a>';
            } elseif ($item['kind'] === 'overdue') {
                echo esc_html(sprintf(
                    /* translators: %d: number of overdue tasks */
                    _n('%d overdue task', '%d overdue tasks', (int) $item['title'], 'foreningsplugin'),
                    (int) $item['title']
                ));
                echo ' <a href="' . esc_url(self::meetingUrl((int) $item['meetingId'])) . '">' . esc_html__('View meeting work', 'foreningsplugin') . '</a>';
            } elseif ($item['kind'] === 'minutes') {
                echo esc_html($item['title']) . ' — ' . esc_html(self::minutesLabel($item['detail'])) . ' ';
                $label = $item['detail'] === 'adjustment' ? __('Review minutes', 'foreningsplugin') : __('Continue', 'foreningsplugin');
                echo '<a href="' . esc_url(self::meetingUrl((int) $item['meetingId']) . '#assoc-minutes') . '">' . esc_html($label) . '</a>';
            } elseif ($item['kind'] === 'board_change') {
                $parts = explode('|', $item['detail'], 2);
                echo esc_html(sprintf(
                    /* translators: 1: role, 2: person, 3: date */
                    __('%1$s changes to %2$s on %3$s.', 'foreningsplugin'),
                    $item['title'],
                    $parts[0] ?? '',
                    $parts[1] ?? ''
                ));
                echo ' <a href="' . esc_url(self::pageUrl('foreningsplugin-board')) . '">' . esc_html__('View board', 'foreningsplugin') . '</a>';
            } else {
                echo esc_html__('Next meeting', 'foreningsplugin') . ': ' . esc_html($item['title']);
                echo ' <span>' . esc_html($item['detail']) . '</span> ';
                echo '<a href="' . esc_url(self::meetingUrl((int) $item['meetingId'])) . '">' . esc_html__('Open meeting', 'foreningsplugin') . '</a>';
            }

            echo '</li>';
        }

        echo '</ul>';
    }

    private static function renderGlance(DashboardSnapshot $snapshot): void
    {
        if (! $snapshot->showMembers) {
            return;
        }

        echo '<h2>' . esc_html__('At a glance', 'foreningsplugin') . '</h2>';
        echo '<ul>';
        echo '<li>' . esc_html(sprintf(
            /* translators: %d: number of people who are members */
            __('Active individual members: %d', 'foreningsplugin'),
            $snapshot->activeMembers
        )) . '</li>';
        echo '<li>' . esc_html(sprintf(
            /* translators: %d: number of memberships with an active period */
            __('Active memberships: %d', 'foreningsplugin'),
            $snapshot->activeMemberships
        )) . '</li>';
        echo '<li>' . esc_html(sprintf(
            /* translators: %d: number of current board assignments */
            __('Board assignments today: %d', 'foreningsplugin'),
            $snapshot->currentBoardCount
        )) . '</li>';

        if ($snapshot->openDecisions !== null) {
            echo '<li>' . esc_html(sprintf(
                /* translators: %d: number of open decisions */
                __('Open decisions: %d', 'foreningsplugin'),
                $snapshot->openDecisions
            )) . '</li>';
        }

        if ($snapshot->openTasks !== null) {
            echo '<li>' . esc_html(sprintf(
                /* translators: %d: number of open tasks */
                __('Open tasks: %d', 'foreningsplugin'),
                $snapshot->openTasks
            )) . '</li>';
        }

        echo '</ul>';
    }

    private static function renderMeetings(DashboardSnapshot $snapshot): void
    {
        if (! $snapshot->showMeetings) {
            return;
        }

        echo '<h2>' . esc_html__('Meetings', 'foreningsplugin') . '</h2>';
        echo '<div class="assoc-dashboard-grid">';
        echo '<section class="assoc-dashboard-card">';
        echo '<h3>' . esc_html__('In progress', 'foreningsplugin') . '</h3>';

        if ($snapshot->inProgress instanceof Meeting) {
            echo '<p>' . esc_html($snapshot->inProgress->title()) . '<br>';
            echo esc_html(sprintf(
                /* translators: 1: date, 2: time */
                __('Scheduled: %1$s %2$s', 'foreningsplugin'),
                $snapshot->inProgress->startsAt()->date(),
                $snapshot->inProgress->startsAt()->time()
            )) . '</p>';
            echo '<p><a href="' . esc_url(self::meetingUrl((int) $snapshot->inProgress->id())) . '">' . esc_html__('Continue meeting', 'foreningsplugin') . '</a></p>';
        } else {
            echo '<p>' . esc_html__('No meeting is in progress.', 'foreningsplugin') . '</p>';
        }

        echo '</section><section class="assoc-dashboard-card">';
        echo '<h3>' . esc_html__('Next meeting', 'foreningsplugin') . '</h3>';

        if ($snapshot->nextMeeting instanceof Meeting) {
            $place = $snapshot->nextMeeting->place();
            echo '<p>' . esc_html($snapshot->nextMeeting->title()) . '<br>';
            echo esc_html($snapshot->nextMeeting->startsAt()->date() . ' ' . $snapshot->nextMeeting->startsAt()->time());

            if ($place !== '') {
                echo '<br>' . esc_html($place);
            }

            echo '</p>';
            echo '<p><a href="' . esc_url(self::meetingUrl((int) $snapshot->nextMeeting->id())) . '">' . esc_html__('Open meeting', 'foreningsplugin') . '</a></p>';
        } else {
            echo '<p>' . esc_html__('No upcoming meeting.', 'foreningsplugin') . '</p>';
        }

        if ($snapshot->canManageMeetings) {
            echo '<p><a href="' . esc_url(self::pageUrl('foreningsplugin-meetings')) . '">' . esc_html__('Plan a meeting', 'foreningsplugin') . '</a></p>';
        }

        echo '</section><section class="assoc-dashboard-card">';
        echo '<h3>' . esc_html__('Latest held meeting', 'foreningsplugin') . '</h3>';

        if ($snapshot->latestHeld instanceof Meeting) {
            echo '<p>' . esc_html($snapshot->latestHeld->title()) . '<br>';
            echo esc_html(sprintf(
                /* translators: %s: date */
                __('Held %s', 'foreningsplugin'),
                $snapshot->latestHeld->startsAt()->date()
            ));
            echo '<br>' . esc_html(self::minutesLabel((string) $snapshot->latestHeldMinutes)) . '</p>';
            echo '<p><a href="' . esc_url(self::meetingUrl((int) $snapshot->latestHeld->id())) . '">' . esc_html__('Open meeting', 'foreningsplugin') . '</a></p>';
        } else {
            echo '<p>' . esc_html__('No held meeting.', 'foreningsplugin') . '</p>';
        }

        echo '</section><section class="assoc-dashboard-card">';
        echo '<h3>' . esc_html__('Latest finalized minutes', 'foreningsplugin') . '</h3>';

        if (is_array($snapshot->latestFinalized)) {
            echo '<p>' . esc_html($snapshot->latestFinalized['title']) . '<br>';
            echo esc_html(sprintf(
                /* translators: %d: revision number */
                __('Revision %d finalized', 'foreningsplugin'),
                $snapshot->latestFinalized['number']
            )) . '</p>';
            echo '<p><a href="' . esc_url(self::meetingUrl($snapshot->latestFinalized['meetingId']) . '#assoc-minutes') . '">' . esc_html__('Open minutes', 'foreningsplugin') . '</a></p>';
        } else {
            echo '<p>' . esc_html__('No finalized minutes.', 'foreningsplugin') . '</p>';
        }

        echo '</section></div>';
    }

    private static function renderBoard(DashboardSnapshot $snapshot): void
    {
        if (! $snapshot->showMembers) {
            return;
        }

        echo '<h2>' . esc_html__('Board', 'foreningsplugin') . '</h2>';

        if ($snapshot->currentBoard === []) {
            echo '<p>' . esc_html__('No current board assignments.', 'foreningsplugin') . '</p>';
        } else {
            echo '<table class="widefat striped"><tbody>';

            foreach ($snapshot->currentBoard as $seat) {
                echo '<tr><th scope="row">' . esc_html($seat['role']) . '</th><td>' . esc_html($seat['name']) . '</td></tr>';
            }

            echo '</tbody></table>';
        }

        echo '<p><a href="' . esc_url(self::pageUrl('foreningsplugin-board')) . '">' . esc_html__('View board', 'foreningsplugin') . '</a></p>';

        if ($snapshot->upcomingBoard !== []) {
            echo '<h3>' . esc_html__('Upcoming board changes', 'foreningsplugin') . '</h3><ul>';

            foreach ($snapshot->upcomingBoard as $change) {
                echo '<li>' . esc_html(sprintf(
                    /* translators: 1: role, 2: person, 3: date */
                    __('%1$s changes to %2$s on %3$s.', 'foreningsplugin'),
                    $change['role'],
                    $change['name'],
                    $change['startsOn']
                )) . '</li>';
            }

            echo '</ul>';
        }
    }

    private static function renderWork(DashboardSnapshot $snapshot): void
    {
        if (! $snapshot->showMeetings) {
            return;
        }

        echo '<h2>' . esc_html__('Decisions and tasks', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %d: number of open decisions */
            __('Open decisions: %d', 'foreningsplugin'),
            (int) $snapshot->openDecisions
        )) . '</p>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %d: number of open tasks */
            __('Open tasks: %d', 'foreningsplugin'),
            (int) $snapshot->openTasks
        )) . '</p>';
        echo '<h3>' . esc_html__('Overdue tasks', 'foreningsplugin') . '</h3>';

        if ($snapshot->overdueTasks === []) {
            echo '<p>' . esc_html__('No overdue tasks.', 'foreningsplugin') . '</p>';

            return;
        }

        echo '<ul>';

        foreach ($snapshot->overdueTasks as $task) {
            $assignee = $task['assignee'] !== null && $task['assignee'] !== '' ? $task['assignee'] : __('Unassigned', 'foreningsplugin');
            echo '<li><a href="' . esc_url(self::meetingUrl($task['meetingId'])) . '">' . esc_html($task['task']) . '</a> ';
            echo esc_html($assignee . ' (' . $task['due'] . ')') . '</li>';
        }

        echo '</ul>';
    }

    private static function renderDocuments(DashboardSnapshot $snapshot): void
    {
        if (! $snapshot->showDocuments) {
            return;
        }

        echo '<h2>' . esc_html__('Documents', 'foreningsplugin') . '</h2>';
        echo '<p><a href="' . esc_url(self::pageUrl('foreningsplugin-documents')) . '">' . esc_html__('View documents', 'foreningsplugin') . '</a></p>';

        if ($snapshot->documents === []) {
            echo '<p>' . esc_html__('No documents to show.', 'foreningsplugin') . '</p>';

            return;
        }

        echo '<ul>';

        foreach ($snapshot->documents as $document) {
            $visibility = DocumentVisibility::tryFrom($document['visibility']);
            $label = match ($visibility) {
                DocumentVisibility::Public => __('Public', 'foreningsplugin'),
                DocumentVisibility::Member => __('Member', 'foreningsplugin'),
                DocumentVisibility::Administrator => __('Administrator', 'foreningsplugin'),
                default => __('Internal', 'foreningsplugin'),
            };
            $download = add_query_arg('assoc_document', (string) $document['id'], home_url('/'));
            echo '<li><a href="' . esc_url($download) . '">' . esc_html($document['title']) . '</a> ';
            echo '<span>' . esc_html($label) . '</span></li>';
        }

        echo '</ul>';
    }

    private static function minutesLabel(string $state): string
    {
        return match ($state) {
            'draft' => __('Minutes draft', 'foreningsplugin'),
            'adjustment' => __('Under adjustment', 'foreningsplugin'),
            'finalized' => __('Minutes finalized', 'foreningsplugin'),
            default => __('Minutes: awaiting draft', 'foreningsplugin'),
        };
    }

    private static function pageUrl(string $page): string
    {
        return add_query_arg('page', $page, admin_url('admin.php'));
    }

    private static function meetingUrl(int $meetingId): string
    {
        return add_query_arg([
            'page' => 'foreningsplugin-meetings',
            'meeting' => (string) $meetingId,
        ], admin_url('admin.php'));
    }
}
