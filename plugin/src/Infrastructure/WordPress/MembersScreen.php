<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\MemberDirectory;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipKind;

final class MembersScreen
{
    public static function render(bool $canEdit): void
    {
        $directory = WordpressPeople::directory();
        $today = AssociationDate::fromIso(wp_date('Y-m-d'));
        $personId = isset($_GET['assoc_person']) ? absint($_GET['assoc_person']) : 0;
        $number = isset($_GET['assoc_number']) ? sanitize_text_field(wp_unslash((string) $_GET['assoc_number'])) : '';
        $create = isset($_GET['assoc_new']) ? sanitize_key((string) $_GET['assoc_new']) : '';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Members', 'foreningsplugin') . '</h1>';
        MembersPage::notice();

        if ($personId > 0) {
            $detail = $directory->personDetail($personId, $today);
            echo $detail === null
                ? '<p>' . esc_html__('That person or membership could not be found.', 'foreningsplugin') . '</p>'
                : self::personMarkup($detail, $canEdit, $directory);
        } elseif ($number !== '') {
            $detail = $directory->membershipDetail($number);
            echo $detail === null
                ? '<p>' . esc_html__('That person or membership could not be found.', 'foreningsplugin') . '</p>'
                : self::membershipMarkup($detail, $canEdit, $directory);
        } elseif ($canEdit && in_array($create, ['ordinary', 'youth', 'family', 'company'], true)) {
            self::createForm($create, $directory);
        } else {
            self::listView($directory, $today, $canEdit);
        }

        echo '</div>';
    }

    private static function listView(MemberDirectory $directory, AssociationDate $today, bool $canEdit): void
    {
        $query = isset($_GET['assoc_q']) ? sanitize_text_field(wp_unslash((string) $_GET['assoc_q'])) : '';
        $kind = isset($_GET['assoc_kind']) ? sanitize_key((string) $_GET['assoc_kind']) : '';
        $state = isset($_GET['assoc_state']) ? sanitize_key((string) $_GET['assoc_state']) : '';
        $rows = $directory->listRows($query, $kind, $state, $today);

        if ($canEdit) {
            echo '<p>';
            foreach ([
                'ordinary' => __('Add ordinary member', 'foreningsplugin'),
                'youth' => __('Add youth member', 'foreningsplugin'),
                'family' => __('Add family membership', 'foreningsplugin'),
                'company' => __('Add company membership', 'foreningsplugin'),
            ] as $slug => $label) {
                echo '<a class="button" href="' . esc_url(self::url(['assoc_new' => $slug])) . '">' . esc_html($label) . '</a> ';
            }
            echo '</p>';
        }

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="foreningsplugin-members">';
        echo '<p><label>' . esc_html__('Search', 'foreningsplugin') . ' <input type="search" name="assoc_q" value="' . esc_attr($query) . '"></label> ';
        echo '<label>' . esc_html__('Type', 'foreningsplugin') . ' <select name="assoc_kind">';
        echo '<option value="">' . esc_html__('All types', 'foreningsplugin') . '</option>';
        foreach (self::kinds() as $slug => $label) {
            echo '<option value="' . esc_attr($slug) . '"' . selected($kind, $slug, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> ';
        echo '<label>' . esc_html__('State', 'foreningsplugin') . ' <select name="assoc_state">';
        echo '<option value="">' . esc_html__('All states', 'foreningsplugin') . '</option>';
        foreach ([
            'active' => __('Active', 'foreningsplugin'),
            'inactive' => __('Inactive', 'foreningsplugin'),
            'history' => __('History', 'foreningsplugin'),
        ] as $slug => $label) {
            echo '<option value="' . esc_attr($slug) . '"' . selected($state, $slug, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> ';
        submit_button(__('Filter', 'foreningsplugin'), 'secondary', '', false);
        echo '</p></form>';

        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Name', 'foreningsplugin'), __('Membership number', 'foreningsplugin'), __('Type', 'foreningsplugin'), __('State', 'foreningsplugin'), __('Dates', 'foreningsplugin'), __('Context', 'foreningsplugin')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($rows === []) {
            $message = ($query === '' && $kind === '' && $state === '')
                ? __('No members yet. Add the association\'s first member.', 'foreningsplugin')
                : __('No members match this search.', 'foreningsplugin');
            echo '<tr><td colspan="6">' . esc_html($message) . '</td></tr>';
        }

        foreach ($rows as $row) {
            $args = $row['person_id'] > 0
                ? ['assoc_person' => (string) $row['person_id']]
                : ['assoc_number' => $row['membership_number']];
            [$started, $ended] = array_pad(explode('|', $row['dates'], 2), 2, '');
            $datesOpen = ($row['dates_open'] ?? false) === true;
            echo '<tr>';
            echo '<td><a href="' . esc_url(self::url($args)) . '">' . esc_html($row['title']) . '</a></td>';
            echo '<td>' . esc_html($row['number']) . '</td>';
            echo '<td>' . esc_html(self::kindLabel($row['kind'])) . '</td>';
            echo '<td>' . esc_html(self::stateLabel($row['state'])) . '</td>';
            echo '<td>' . esc_html($datesOpen ? self::span($started, $ended === '' ? null : $ended) : ($ended === '' ? $started : self::span($started, $ended))) . '</td>';
            echo '<td>' . esc_html($row['context']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        self::exchange($canEdit);
    }

    /**
     * @param array<string, mixed> $detail
     */
    private static function personMarkup(array $detail, bool $canEdit, MemberDirectory $directory): string
    {
        ob_start();
        $personId = (int) $detail['person_id'];
        echo '<p><a href="' . esc_url(self::url()) . '">' . esc_html__('Back to members', 'foreningsplugin') . '</a></p>';
        echo '<h2>' . esc_html__('Person', 'foreningsplugin') . '</h2>';
        echo '<p><strong>' . esc_html($detail['first_name'] . ' ' . $detail['last_name']) . '</strong><br>';
        echo esc_html($detail['active_member'] ? __('Currently an individual member.', 'foreningsplugin') : __('Not currently an individual member.', 'foreningsplugin'));
        if ($detail['deceased'] === true) {
            echo '<br>' . esc_html__('Deceased', 'foreningsplugin');
        }
        echo '</p>';

        if ($canEdit && $detail['deceased'] !== true) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_update_person">';
            echo '<input type="hidden" name="person_id" value="' . esc_attr((string) $personId) . '">';
            wp_nonce_field('assoc_update_person');
            self::input('first_name', __('First name', 'foreningsplugin'), 'text', true, (string) $detail['first_name']);
            self::input('last_name', __('Last name', 'foreningsplugin'), 'text', true, (string) $detail['last_name']);
            self::input('email', __('Email', 'foreningsplugin'), 'email', false, (string) $detail['email']);
            self::input('birth_date', __('Birth date', 'foreningsplugin'), 'date', false, (string) ($detail['birth_date'] ?? ''));
            submit_button(__('Save person', 'foreningsplugin'));
            echo '</form>';
        } else {
            echo '<p>' . esc_html((string) $detail['email']) . '</p>';
            if (is_string($detail['birth_date']) && $detail['birth_date'] !== '') {
                echo '<p>' . esc_html(__('Birth date', 'foreningsplugin') . ': ' . $detail['birth_date']) . '</p>';
            }
        }

        echo '<h2>' . esc_html__('Membership', 'foreningsplugin') . '</h2>';
        $memberships = is_array($detail['memberships']) ? $detail['memberships'] : [];

        if ($memberships === []) {
            echo '<p>' . esc_html__('No membership is recorded for this person.', 'foreningsplugin') . '</p>';
        }

        foreach ($memberships as $membership) {
            if (! is_array($membership)) {
                continue;
            }

            self::membershipBlock($membership, $canEdit, $personId, $detail['deceased'] === true);
        }

        self::guardians($personId, $canEdit, $directory);
        self::identity($personId, $canEdit);

        if ($canEdit && $detail['deceased'] !== true) {
            echo '<h2>' . esc_html__('Actions', 'foreningsplugin') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_mark_deceased">';
            echo '<input type="hidden" name="person_id" value="' . esc_attr((string) $personId) . '">';
            echo '<input type="hidden" name="return_person" value="' . esc_attr((string) $personId) . '">';
            wp_nonce_field('assoc_mark_deceased');
            self::input('deceased_on', __('Date', 'foreningsplugin'), 'date', true, '');
            self::confirm(__('Mark this person as deceased? Open memberships end on that date and the history is retained.', 'foreningsplugin'));
            submit_button(__('Mark person deceased', 'foreningsplugin'), 'delete');
            echo '</form>';
        }

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $membership
     */
    private static function membershipMarkup(array $membership, bool $canEdit, MemberDirectory $directory): string
    {
        ob_start();
        echo '<p><a href="' . esc_url(self::url()) . '">' . esc_html__('Back to members', 'foreningsplugin') . '</a></p>';
        self::membershipBlock($membership, $canEdit, 0, false);
        if ($canEdit && ($membership['kind'] ?? '') === MembershipKind::Company->value) {
            self::contactForm((int) $membership['membership_id'], (string) $membership['number'], $directory);
        }
        if ($canEdit && ($membership['kind'] ?? '') === MembershipKind::Family->value) {
            self::participantForm((int) $membership['membership_id'], (string) $membership['number'], $directory);
        }

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $membership
     */
    private static function membershipBlock(array $membership, bool $canEdit, int $personId, bool $deceased): void
    {
        $kind = (string) ($membership['kind'] ?? '');
        echo '<h3>' . esc_html(self::kindLabel($kind) . ' ' . (string) $membership['number']) . '</h3>';

        if (($membership['organization'] ?? '') !== '') {
            echo '<p>' . esc_html((string) $membership['organization']);
            if (($membership['organization_number'] ?? '') !== '') {
                echo '<br>' . esc_html(__('Organization number', 'foreningsplugin') . ' ' . (string) $membership['organization_number']);
            }
            echo '</p>';
            echo '<p>' . esc_html__('Company contacts are not individual members.', 'foreningsplugin') . '</p>';
        }

        echo '<h4>' . esc_html__('History', 'foreningsplugin') . '</h4>';
        $periods = is_array($membership['periods'] ?? null) ? $membership['periods'] : [];

        if ($periods === []) {
            echo '<p>' . esc_html__('No previous membership periods.', 'foreningsplugin') . '</p>';
        } else {
            echo '<ul>';
            foreach ($periods as $period) {
                if (! is_array($period)) {
                    continue;
                }
                echo '<li>' . esc_html(self::span((string) $period['started_on'], $period['ended_on'] ?? null) . ' ' . self::periodStatus((string) $period['status'])) . '</li>';
            }
            echo '</ul>';
        }

        echo '<h4>' . esc_html($kind === MembershipKind::Company->value ? __('Contacts', 'foreningsplugin') : __('Participants', 'foreningsplugin')) . '</h4>';
        $participants = is_array($membership['participants'] ?? null) ? $membership['participants'] : [];

        if ($participants === []) {
            echo '<p>' . esc_html__('No participants are recorded.', 'foreningsplugin') . '</p>';
        } else {
            echo '<ul>';
            foreach ($participants as $participant) {
                if (! is_array($participant)) {
                    continue;
                }
                $name = (string) $participant['name'];
                $text = $name . ', ' . self::roleLabel((string) $participant['role']);
                if (($participant['primary'] ?? false) === true) {
                    $text .= ', ' . __('Primary contact', 'foreningsplugin');
                }
                $text .= ', ' . self::span((string) $participant['started_on'], $participant['ended_on'] ?? null);
                echo '<li>' . esc_html($text);
                if ($canEdit && ($participant['open'] ?? false) === true) {
                    self::endParticipationForm((int) $membership['membership_id'], (int) $participant['person_id'], (string) $membership['number']);
                }
                echo '</li>';
            }
            echo '</ul>';
        }

        if (! $canEdit || $deceased) {
            return;
        }

        foreach ($periods as $period) {
            if (! is_array($period) || ($period['open'] ?? false) !== true) {
                continue;
            }

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_end_membership">';
            echo '<input type="hidden" name="membership_id" value="' . esc_attr((string) $period['period_id']) . '">';
            echo '<input type="hidden" name="return_person" value="' . esc_attr((string) $personId) . '">';
            echo '<input type="hidden" name="return_number" value="' . esc_attr((string) $membership['number']) . '">';
            wp_nonce_field('assoc_end_membership');
            self::input('ended_on', __('End date', 'foreningsplugin'), 'date', true, '');
            self::confirm(__('End membership on this date? Membership history is retained. Current board assignments may be shortened if no other membership coverage remains.', 'foreningsplugin'));
            submit_button(__('End membership', 'foreningsplugin'), 'delete');
            echo '</form>';
        }

        $hasOpen = false;
        foreach ($periods as $period) {
            if (is_array($period) && ($period['open'] ?? false) === true) {
                $hasOpen = true;
            }
        }

        if (! $hasOpen) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_add_membership">';
            echo '<input type="hidden" name="membership_id" value="' . esc_attr((string) $membership['membership_id']) . '">';
            echo '<input type="hidden" name="return_person" value="' . esc_attr((string) $personId) . '">';
            wp_nonce_field('assoc_add_membership');
            self::input('started_on', __('Start date', 'foreningsplugin'), 'date', true, '');
            submit_button(__('Start new membership period', 'foreningsplugin'));
            echo '</form>';
        }

        if ($personId > 0 && $kind === MembershipKind::Family->value) {
            self::participantForm((int) $membership['membership_id'], (string) $membership['number'], WordpressPeople::directory());
        }
    }

    private static function participantForm(int $membershipId, string $number, MemberDirectory $directory): void
    {
        echo '<h3>' . esc_html__('Add family participant', 'foreningsplugin') . '</h3>';
        echo '<p>' . esc_html__('Choose an existing person, or leave that choice empty and enter a new person. The plugin does not merge people automatically.', 'foreningsplugin') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_add_family_participant">';
        echo '<input type="hidden" name="membership_id" value="' . esc_attr((string) $membershipId) . '">';
        echo '<input type="hidden" name="return_number" value="' . esc_attr($number) . '">';
        wp_nonce_field('assoc_add_family_participant');
        self::personSelect($directory, 'person_id', __('Existing person', 'foreningsplugin'));
        echo '<p><label>' . esc_html__('Role', 'foreningsplugin') . ' <select name="participant_role">';
        echo '<option value="member">' . esc_html__('Member', 'foreningsplugin') . '</option>';
        echo '</select></label></p>';
        echo '<p><label><input type="checkbox" name="primary_contact" value="1"> ' . esc_html__('Primary contact', 'foreningsplugin') . '</label></p>';
        self::input('started_on', __('Start date', 'foreningsplugin'), 'date', true, '');
        self::input('first_name', __('First name', 'foreningsplugin'), 'text', false, '');
        self::input('last_name', __('Last name', 'foreningsplugin'), 'text', false, '');
        self::input('email', __('Email', 'foreningsplugin'), 'email', false, '');
        self::input('birth_date', __('Birth date', 'foreningsplugin'), 'date', false, '');
        submit_button(__('Add family participant', 'foreningsplugin'));
        echo '</form>';
    }

    private static function contactForm(int $membershipId, string $number, MemberDirectory $directory): void
    {
        echo '<h3>' . esc_html__('Add company contact', 'foreningsplugin') . '</h3>';
        echo '<p>' . esc_html__('A company contact is not an individual member.', 'foreningsplugin') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_add_company_contact">';
        echo '<input type="hidden" name="membership_id" value="' . esc_attr((string) $membershipId) . '">';
        echo '<input type="hidden" name="return_number" value="' . esc_attr($number) . '">';
        wp_nonce_field('assoc_add_company_contact');
        self::personSelect($directory, 'person_id', __('Existing person', 'foreningsplugin'));
        self::input('started_on', __('Start date', 'foreningsplugin'), 'date', true, '');
        self::input('first_name', __('First name', 'foreningsplugin'), 'text', false, '');
        self::input('last_name', __('Last name', 'foreningsplugin'), 'text', false, '');
        self::input('email', __('Email', 'foreningsplugin'), 'email', false, '');
        submit_button(__('Add company contact', 'foreningsplugin'));
        echo '</form>';
    }

    private static function endParticipationForm(int $membershipId, int $personId, string $number): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_end_participation">';
        echo '<input type="hidden" name="membership_id" value="' . esc_attr((string) $membershipId) . '">';
        echo '<input type="hidden" name="person_id" value="' . esc_attr((string) $personId) . '">';
        echo '<input type="hidden" name="return_person" value="' . esc_attr((string) $personId) . '">';
        echo '<input type="hidden" name="return_number" value="' . esc_attr($number) . '">';
        wp_nonce_field('assoc_end_participation');
        echo '<label>' . esc_html__('End date', 'foreningsplugin') . ' <input type="date" name="ended_on" required></label> ';
        self::confirm(__('End this participation? Earlier participation history is retained. A board assignment may be shortened if no other membership coverage remains.', 'foreningsplugin'));
        submit_button(__('End participation', 'foreningsplugin'), 'delete', 'submit', false);
        echo '</form>';
    }

    private static function guardians(int $personId, bool $canEdit, MemberDirectory $directory): void
    {
        echo '<h2>' . esc_html__('Guardians', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('Guardian relationships are recorded only when someone adds them. The plugin does not infer a guardian from a shared name, family membership or address.', 'foreningsplugin') . '</p>';
        $relationships = WordpressPeople::guardians()->relationshipsFor($personId);
        $approvals = WordpressPeople::guardians()->approvalsFor($personId);
        $names = [];

        foreach ($directory->peopleChoices() as $choice) {
            $names[$choice['person_id']] = $choice['label'];
        }

        if ($relationships === []) {
            echo '<p>' . esc_html__('No guardian relationships recorded.', 'foreningsplugin') . '</p>';
        } else {
            echo '<ul>';
            foreach ($relationships as $relationship) {
                $label = $names[$relationship->guardianPersonId()] ?? __('Guardian', 'foreningsplugin');
                echo '<li>' . esc_html($label . ', ' . $relationship->relationship() . ', ' . self::span($relationship->startedOn()?->iso() ?? '', $relationship->endedOn()?->iso()));
                if ($canEdit && $relationship->endedOn() === null && $relationship->id() !== null) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    echo '<input type="hidden" name="action" value="assoc_end_guardian">';
                    echo '<input type="hidden" name="child_person_id" value="' . esc_attr((string) $personId) . '">';
                    echo '<input type="hidden" name="relationship_id" value="' . esc_attr((string) $relationship->id()) . '">';
                    echo '<input type="hidden" name="return_person" value="' . esc_attr((string) $personId) . '">';
                    wp_nonce_field('assoc_end_guardian');
                    echo '<label>' . esc_html__('End date', 'foreningsplugin') . ' <input type="date" name="ended_on" required></label> ';
                    self::confirm(__('End this guardian relationship? The earlier record remains.', 'foreningsplugin'));
                    submit_button(__('End guardian relationship', 'foreningsplugin'), 'secondary', 'submit', false);
                    echo '</form>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '<h3>' . esc_html__('Guardian approvals', 'foreningsplugin') . '</h3>';
        echo '<p>' . esc_html__('An approval record says what the association recorded. It does not prove that the approval was legally valid.', 'foreningsplugin') . '</p>';

        if ($approvals === []) {
            echo '<p>' . esc_html__('No guardian approvals recorded.', 'foreningsplugin') . '</p>';
        } else {
            echo '<ul>';
            foreach ($approvals as $approval) {
                $label = $names[$approval->guardianPersonId()] ?? __('Guardian', 'foreningsplugin');
                $withdrawn = $approval->withdrawnAt() !== null;
                echo '<li>' . esc_html($label . ', ' . $approval->purpose() . ', ' . $approval->method() . ', ' . $approval->approvedAt()->format('Y-m-d') . ', ' . $approval->noticeVersion());
                if ($withdrawn) {
                    echo ' ' . esc_html__('Withdrawn', 'foreningsplugin');
                } elseif ($canEdit && $approval->id() !== null) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    echo '<input type="hidden" name="action" value="assoc_withdraw_guardian_approval">';
                    echo '<input type="hidden" name="approval_id" value="' . esc_attr((string) $approval->id()) . '">';
                    echo '<input type="hidden" name="return_person" value="' . esc_attr((string) $personId) . '">';
                    wp_nonce_field('assoc_withdraw_guardian_approval');
                    echo '<label>' . esc_html__('Withdrawal date', 'foreningsplugin') . ' <input type="date" name="withdrawn_on" required></label> ';
                    self::confirm(__('Mark this guardian approval as withdrawn? The record remains.', 'foreningsplugin'));
                    submit_button(__('Withdraw guardian approval', 'foreningsplugin'), 'secondary', 'submit', false);
                    echo '</form>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }

        if (! $canEdit) {
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_add_guardian">';
        echo '<input type="hidden" name="child_person_id" value="' . esc_attr((string) $personId) . '">';
        echo '<input type="hidden" name="return_person" value="' . esc_attr((string) $personId) . '">';
        wp_nonce_field('assoc_add_guardian');
        self::personSelect($directory, 'guardian_person_id', __('Existing guardian', 'foreningsplugin'));
        self::input('guardian_first_name', __('Guardian first name', 'foreningsplugin'), 'text', false, '');
        self::input('guardian_last_name', __('Guardian last name', 'foreningsplugin'), 'text', false, '');
        self::input('guardian_email', __('Email', 'foreningsplugin'), 'email', false, '');
        self::input('relationship', __('Relationship', 'foreningsplugin'), 'text', false, '');
        self::input('started_on', __('Start date', 'foreningsplugin'), 'date', false, '');
        submit_button(__('Add guardian', 'foreningsplugin'));
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_record_guardian_approval">';
        echo '<input type="hidden" name="child_person_id" value="' . esc_attr((string) $personId) . '">';
        echo '<input type="hidden" name="return_person" value="' . esc_attr((string) $personId) . '">';
        wp_nonce_field('assoc_record_guardian_approval');
        self::personSelect($directory, 'guardian_person_id', __('Guardian', 'foreningsplugin'));
        self::input('purpose', __('Purpose', 'foreningsplugin'), 'text', true, '');
        self::input('basis_note', __('Basis note', 'foreningsplugin'), 'text', false, '');
        self::input('approved_on', __('Approval date', 'foreningsplugin'), 'date', true, '');
        self::input('method', __('Method', 'foreningsplugin'), 'text', true, '');
        self::input('notice_version', __('Notice version', 'foreningsplugin'), 'text', false, '');
        self::input('note', __('Note', 'foreningsplugin'), 'text', false, '');
        submit_button(__('Record guardian approval', 'foreningsplugin'));
        echo '</form>';
    }

    private static function identity(int $personId, bool $canEdit): void
    {
        echo '<h2>' . esc_html__('Protected identity data', 'foreningsplugin') . '</h2>';
        $recorded = WordpressPeople::identity()->isRecorded($personId);
        $canView = current_user_can(Capabilities::VIEW_PERSONAL_IDENTITY_NUMBERS);
        $canEditIdentity = current_user_can(Capabilities::EDIT_PERSONAL_IDENTITY_NUMBERS);

        if (! $recorded) {
            echo '<p>' . esc_html__('No personal identity number is recorded. A membership does not require one.', 'foreningsplugin') . '</p>';
        } elseif (! $canView) {
            echo '<p>' . esc_html__('Personal identity number: Recorded', 'foreningsplugin') . '</p>';
        } else {
            $record = WordpressPeople::identity()->authorizedRecord($personId);

            if (is_array($record)) {
                echo '<p>' . esc_html(__('Personal identity number', 'foreningsplugin') . ': ' . $record['number']) . '</p>';
                echo '<p>' . esc_html(__('Purpose', 'foreningsplugin') . ': ' . $record['purpose']) . '</p>';
                echo '<p>' . esc_html(__('Basis note', 'foreningsplugin') . ': ' . $record['basis_note']) . '</p>';
                echo '<p>' . esc_html(__('Collected', 'foreningsplugin') . ': ' . $record['collected_on']) . '</p>';
            }
        }

        if (! $canEditIdentity) {
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_store_identity">';
        echo '<input type="hidden" name="person_id" value="' . esc_attr((string) $personId) . '">';
        echo '<input type="hidden" name="return_person" value="' . esc_attr((string) $personId) . '">';
        wp_nonce_field('assoc_store_identity');
        self::input('personal_identity_number', __('Personal identity number', 'foreningsplugin'), 'text', true, '');
        echo '<p>' . esc_html__('Checked for format and checksum only, including coordination numbers. Not checked against an external register. Not stored encrypted.', 'foreningsplugin') . '</p>';
        self::input('purpose', __('Purpose', 'foreningsplugin'), 'text', true, '');
        self::input('basis_note', __('Basis note', 'foreningsplugin'), 'text', false, '');
        self::input('collected_on', __('Collected', 'foreningsplugin'), 'date', true, '');
        submit_button(__('Save identity record', 'foreningsplugin'));
        echo '</form>';

        if ($recorded) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_remove_identity">';
            echo '<input type="hidden" name="person_id" value="' . esc_attr((string) $personId) . '">';
            echo '<input type="hidden" name="return_person" value="' . esc_attr((string) $personId) . '">';
            wp_nonce_field('assoc_remove_identity');
            self::confirm(__('Remove the personal identity number? Membership history is retained.', 'foreningsplugin'));
            submit_button(__('Remove personal identity number', 'foreningsplugin'), 'delete');
            echo '</form>';
        }
    }

    private static function createForm(string $kind, MemberDirectory $directory): void
    {
        echo '<p><a href="' . esc_url(self::url()) . '">' . esc_html__('Back to members', 'foreningsplugin') . '</a></p>';
        echo '<h2>' . esc_html(self::kindLabel($kind)) . '</h2>';

        if ($kind === 'company') {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_register_company">';
            wp_nonce_field('assoc_register_company');
            self::input('organization_name', __('Organization name', 'foreningsplugin'), 'text', true, '');
            self::input('organization_number', __('Organization number', 'foreningsplugin'), 'text', false, '');
            echo '<p>' . esc_html__('The organization number is checked for format and checksum only. It is not checked against a company register.', 'foreningsplugin') . '</p>';
            self::input('email', __('Email', 'foreningsplugin'), 'email', false, '');
            self::input('postal_address', __('Postal address', 'foreningsplugin'), 'text', false, '');
            self::input('membership_number', __('Membership number', 'foreningsplugin'), 'text', true, '');
            self::input('started_on', __('Start date', 'foreningsplugin'), 'date', true, '');
            echo '<p>' . esc_html__('A company contact is not an individual member. You can add contacts after the membership is saved.', 'foreningsplugin') . '</p>';
            submit_button(__('Add company membership', 'foreningsplugin'));
            echo '</form>';

            return;
        }

        if ($kind === 'youth') {
            echo '<p>' . esc_html__('A personal identity number is not required. Guardian approval is not required just because a person is under 18. The association can record a guardian and an approval where it needs that record.', 'foreningsplugin') . '</p>';
        }

        if ($kind === 'family') {
            echo '<p>' . esc_html__('A family membership is one membership shared by the people who take part. Add the primary contact here, then add the other participants.', 'foreningsplugin') . '</p>';
        }

        echo '<h3>' . esc_html__('New person', 'foreningsplugin') . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_register_person">';
        echo '<input type="hidden" name="membership_kind" value="' . esc_attr($kind) . '">';
        wp_nonce_field('assoc_register_person');
        self::input('first_name', __('First name', 'foreningsplugin'), 'text', true, '');
        self::input('last_name', __('Last name', 'foreningsplugin'), 'text', true, '');
        self::input('email', __('Email', 'foreningsplugin'), 'email', false, '');
        self::input('birth_date', __('Birth date', 'foreningsplugin'), 'date', $kind === 'youth', '');
        self::input('membership_number', __('Membership number', 'foreningsplugin'), 'text', true, '');
        self::input('started_on', __('Start date', 'foreningsplugin'), 'date', true, '');
        submit_button($kind === 'family' ? __('Add family membership', 'foreningsplugin') : __('Add member', 'foreningsplugin'));
        echo '</form>';

        echo '<h3>' . esc_html__('Existing person', 'foreningsplugin') . '</h3>';
        echo '<p>' . esc_html__('Choosing a person here is explicit. People are not merged because they share an email address.', 'foreningsplugin') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_open_membership">';
        echo '<input type="hidden" name="membership_kind" value="' . esc_attr($kind) . '">';
        wp_nonce_field('assoc_open_membership');
        self::personSelect($directory, 'person_id', __('Existing person', 'foreningsplugin'));
        if ($kind === 'youth') {
            echo '<p>' . esc_html__('A youth membership needs a birth date on the person. Add that date on the person first. A personal identity number is not used to fill it in.', 'foreningsplugin') . '</p>';
        }
        self::input('membership_number', __('Membership number', 'foreningsplugin'), 'text', true, '');
        self::input('started_on', __('Start date', 'foreningsplugin'), 'date', true, '');
        submit_button(__('Add membership for this person', 'foreningsplugin'));
        echo '</form>';
    }

    private static function exchange(bool $canEdit): void
    {
        if ($canEdit) {
            echo '<h2>' . esc_html__('Import', 'foreningsplugin') . '</h2>';
            echo '<p>' . esc_html__('The file is UTF-8 and semicolon-separated, with one row per membership period. A membership number that already exists is left unchanged. The import does not link a WordPress account and does not rename a person who already exists.', 'foreningsplugin') . '</p>';
            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_import_members">';
            wp_nonce_field('assoc_import_members');
            echo '<p><input type="file" name="member_csv" accept=".csv,text/csv" required></p>';
            submit_button(__('Import members', 'foreningsplugin'));
            echo '</form>';
        }

        if (! current_user_can(Capabilities::EXPORT_MEMBERS)) {
            return;
        }

        echo '<h2>' . esc_html__('Export', 'foreningsplugin') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_export_members">';
        wp_nonce_field('assoc_export_members');
        submit_button(__('Export members', 'foreningsplugin'));
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_export_member_structure">';
        wp_nonce_field('assoc_export_member_structure');
        echo '<p>' . esc_html__('The structured file can describe memberships, periods, participants and organizations. It does not contain personal identity numbers.', 'foreningsplugin') . '</p>';
        submit_button(__('Export membership structure', 'foreningsplugin'));
        echo '</form>';
    }

    private static function personSelect(MemberDirectory $directory, string $name, string $label): void
    {
        echo '<p><label>' . esc_html($label) . ' <select name="' . esc_attr($name) . '">';
        echo '<option value="0">' . esc_html__('New person', 'foreningsplugin') . '</option>';

        foreach ($directory->peopleChoices() as $choice) {
            echo '<option value="' . esc_attr((string) $choice['person_id']) . '">' . esc_html($choice['label']) . '</option>';
        }

        echo '</select></label></p>';
    }

    private static function confirm(string $label): void
    {
        echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html($label) . '</label></p>';
    }

    private static function input(string $name, string $label, string $type, bool $required, string $value): void
    {
        echo '<p><label>' . esc_html($label) . ' ';
        echo '<input class="regular-text" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($required ? ' required' : '') . ($type === 'text' && $name === 'personal_identity_number' ? ' autocomplete="off"' : '') . '>';
        echo '</label></p>';
    }

    /**
     * @param array<string, string> $args
     */
    private static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => 'foreningsplugin-members'], $args), admin_url('admin.php'));
    }

    /**
     * @return array<string, string>
     */
    private static function kinds(): array
    {
        return [
            'ordinary' => __('Ordinary', 'foreningsplugin'),
            'youth' => __('Youth', 'foreningsplugin'),
            'family' => __('Family', 'foreningsplugin'),
            'company' => __('Company', 'foreningsplugin'),
        ];
    }

    private static function kindLabel(string $kind): string
    {
        return self::kinds()[$kind] ?? '';
    }

    private static function stateLabel(string $state): string
    {
        return match ($state) {
            'active' => __('Active', 'foreningsplugin'),
            'history' => __('Previous membership', 'foreningsplugin'),
            'contact' => __('Company contact', 'foreningsplugin'),
            'deceased' => __('Deceased', 'foreningsplugin'),
            default => __('No current membership', 'foreningsplugin'),
        };
    }

    private static function periodStatus(string $status): string
    {
        return match ($status) {
            'active' => __('Active', 'foreningsplugin'),
            'pending' => __('Pending', 'foreningsplugin'),
            'dormant' => __('Dormant', 'foreningsplugin'),
            'ended' => __('Ended', 'foreningsplugin'),
            default => '',
        };
    }

    private static function roleLabel(string $role): string
    {
        return $role === 'contact' ? __('Company contact', 'foreningsplugin') : __('Member', 'foreningsplugin');
    }

    private static function span(string $startedOn, ?string $endedOn): string
    {
        if ($startedOn === '' && ($endedOn === null || $endedOn === '')) {
            return '';
        }

        if ($startedOn === '') {
            return $endedOn ?? '';
        }

        $end = ($endedOn === null || $endedOn === '') ? __('present', 'foreningsplugin') : $endedOn;

        return $startedOn . ' – ' . $end;
    }
}
