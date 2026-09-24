<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardRuleException;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Domain\Membership\MembershipStatus;
use Foreningssystem\Domain\Person\PersonStatus;

final class MembersPage
{
    public static function registerPerson(): void
    {
        self::guardEdit('assoc_register_person');

        try {
            $kind = self::text('membership_kind');
            $birth = self::text('birth_date');
            WordpressPeople::service()->register(
                self::text('first_name'),
                self::text('last_name'),
                self::text('email'),
                self::text('membership_number'),
                $kind === '' ? 'ordinary' : $kind,
                AssociationDate::fromIso(self::text('started_on')),
                $birth === '' ? null : AssociationDate::fromIso($birth),
                AssociationDate::fromIso(wp_date('Y-m-d'))
            );
            self::redirect('created');
        } catch (MembershipRuleException $error) {
            self::redirect($error->getMessage() === 'Membership number is already used.' ? 'duplicate_number' : 'overlap');
        } catch (\InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function addMembership(): void
    {
        self::guardEdit('assoc_add_membership');

        try {
            WordpressPeople::service()->addPeriod(
                self::integer('membership_id'),
                AssociationDate::fromIso(self::text('started_on'))
            );
            self::redirect('renewed');
        } catch (MembershipRuleException $error) {
            $message = $error->getMessage();
            self::redirect(match ($message) {
                'Membership number is already used.' => 'duplicate_number',
                'A deceased person cannot start a membership.' => 'deceased_period',
                default => 'overlap',
            });
        } catch (\InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function registerCompany(): void
    {
        self::guardEdit('assoc_register_company');

        try {
            WordpressPeople::companies()->register(
                self::text('organization_name'),
                self::text('organization_number'),
                self::text('email'),
                self::text('postal_address'),
                self::text('membership_number'),
                AssociationDate::fromIso(self::text('started_on')),
                null
            );
            self::redirect('created');
        } catch (MembershipRuleException $error) {
            self::redirect($error->getMessage() === 'Membership number is already used.' ? 'duplicate_number' : 'invalid');
        } catch (\InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function addFamilyParticipant(): void
    {
        self::guardEdit('assoc_add_family_participant');

        try {
            $startedOn = AssociationDate::fromIso(self::text('started_on'));
            $personId = self::integer('person_id');

            if ($personId > 0) {
                WordpressPeople::service()->addParticipant(
                    self::integer('membership_id'),
                    $personId,
                    \Foreningssystem\Domain\Membership\ParticipantRole::Member,
                    false,
                    $startedOn
                );
            } else {
                WordpressPeople::service()->addPersonToMembership(
                    self::integer('membership_id'),
                    self::text('first_name'),
                    self::text('last_name'),
                    self::text('email'),
                    self::text('birth_date') === '' ? null : AssociationDate::fromIso(self::text('birth_date')),
                    $startedOn,
                    \Foreningssystem\Domain\Membership\ParticipantRole::Member,
                    false,
                    AssociationDate::fromIso(wp_date('Y-m-d'))
                );
            }

            self::redirect('participant_saved');
        } catch (MembershipRuleException) {
            self::redirect('overlap');
        } catch (\InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function storeIdentity(): void
    {
        if (! current_user_can(Capabilities::EDIT_PERSONAL_IDENTITY_NUMBERS)) {
            wp_die(esc_html__('You do not have permission to change personal identity numbers.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer('assoc_store_identity');

        try {
            WordpressPeople::identity()->store(
                self::integer('person_id'),
                self::text('personal_identity_number'),
                self::text('purpose'),
                self::text('basis_note'),
                AssociationDate::fromIso(self::text('collected_on')),
                AssociationDate::fromIso(wp_date('Y-m-d')),
                get_current_user_id()
            );
            self::redirect('identity_saved');
        } catch (\InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function removeIdentity(): void
    {
        if (! current_user_can(Capabilities::EDIT_PERSONAL_IDENTITY_NUMBERS)) {
            wp_die(esc_html__('You do not have permission to change personal identity numbers.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer('assoc_remove_identity');
        WordpressPeople::identity()->remove(self::integer('person_id'), get_current_user_id());
        self::redirect('identity_removed');
    }

    public static function addGuardian(): void
    {
        self::guardEdit('assoc_add_guardian');

        try {
            $guardianId = self::integer('guardian_person_id');

            if ($guardianId < 1) {
                $guardianId = WordpressPeople::service()->rememberPerson(
                    self::text('guardian_first_name'),
                    self::text('guardian_last_name'),
                    self::text('guardian_email'),
                    null,
                    AssociationDate::fromIso(wp_date('Y-m-d'))
                );
            }

            WordpressPeople::guardians()->relate(
                self::integer('child_person_id'),
                $guardianId,
                self::text('relationship') === '' ? 'guardian' : self::text('relationship'),
                null
            );
            self::redirect('guardian_saved');
        } catch (\InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function recordGuardianApproval(): void
    {
        self::guardEdit('assoc_record_guardian_approval');

        try {
            WordpressPeople::guardians()->approve(
                self::integer('child_person_id'),
                self::integer('guardian_person_id'),
                self::text('purpose'),
                self::text('basis_note'),
                new \DateTimeImmutable(self::text('approved_on') . ' 00:00:00'),
                self::text('method'),
                self::text('notice_version'),
                self::text('note'),
                get_current_user_id()
            );
            self::redirect('approval_saved');
        } catch (\InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function exportStructure(): void
    {
        if (! current_user_can(Capabilities::EXPORT_MEMBERS)) {
            wp_die(esc_html__('You do not have permission to export members.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer('assoc_export_member_structure');
        $csv = WordpressPeople::exchange()->exportStructure();
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="medlemskap.csv"');
        header('Content-Length: ' . (string) strlen($csv));
        echo $csv;
        exit;
    }

    public static function endMembership(): void
    {
        self::guardEdit('assoc_end_membership');

        try {
            WordpressPeople::service()->endMembership(
                self::integer('membership_id'),
                AssociationDate::fromIso(self::text('ended_on'))
            );
            self::redirect('ended');
        } catch (BoardRuleException) {
            self::redirect('assignment');
        } catch (MembershipRuleException) {
            self::redirect('already_ended');
        } catch (\InvalidArgumentException) {
            self::redirect('invalid');
        }
    }

    public static function exportMembers(): void
    {
        if (! current_user_can(Capabilities::EXPORT_MEMBERS)) {
            wp_die(esc_html__('You do not have permission to export members.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer('assoc_export_members');
        $csv = WordpressPeople::exchange()->export();
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="medlemmar.csv"');
        header('Content-Length: ' . (string) strlen($csv));
        echo $csv;
        exit;
    }

    public static function importMembers(): void
    {
        self::guardEdit('assoc_import_members');
        $file = $_FILES['member_csv'] ?? null;

        if (
            ! is_array($file)
            || ! isset($file['tmp_name'], $file['size'])
            || ! is_string($file['tmp_name'])
            || ! is_uploaded_file($file['tmp_name'])
        ) {
            self::redirect('invalid');
        }

        if ((int) $file['size'] > 2097152) {
            self::redirect('import_too_large');
        }

        $csv = file_get_contents($file['tmp_name']);

        if ($csv === false) {
            self::redirect('invalid');
        }

        try {
            $result = WordpressPeople::exchange()->import($csv);
        } catch (NotAllowed) {
            wp_die(esc_html__('You do not have permission to import members.', 'foreningsplugin'), '', ['response' => 403]);
        } catch (\InvalidArgumentException) {
            self::redirect('import_invalid');
        }

        set_transient('assoc_member_import_' . get_current_user_id(), $result->errors(), MINUTE_IN_SECONDS);
        self::redirect('import_done', [
            'assoc_created' => (string) $result->created(),
            'assoc_skipped' => (string) $result->skipped(),
        ]);
    }

    public static function markDeceased(): void
    {
        self::guardEdit('assoc_mark_deceased');

        try {
            WordpressPeople::service()->markDeceased(
                self::integer('person_id'),
                AssociationDate::fromIso(self::text('deceased_on'))
            );
            self::redirect('deceased');
        } catch (BoardRuleException) {
            self::redirect('assignment');
        } catch (\InvalidArgumentException | MembershipRuleException) {
            self::redirect('invalid');
        }
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::VIEW_MEMBERS)) {
            wp_die(esc_html__('You do not have permission to view members.', 'foreningsplugin'));
        }

        $canEdit = current_user_can(Capabilities::EDIT_MEMBERS);
        $records = WordpressPeople::service()->listPeople();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Members', 'foreningsplugin') . '</h1>';
        self::notice();

        if ($canEdit) {
            echo '<h2>' . esc_html__('New person', 'foreningsplugin') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_register_person">';
            wp_nonce_field('assoc_register_person');
            self::field('first_name', __('First name', 'foreningsplugin'), 'text', true);
            self::field('last_name', __('Last name', 'foreningsplugin'), 'text', true);
            self::field('email', __('Email', 'foreningsplugin'), 'email', false);
            self::field('membership_number', __('Membership number', 'foreningsplugin'), 'text', true);
            echo '<p><label>' . esc_html__('Membership kind', 'foreningsplugin') . ' <select name="membership_kind">';
            foreach ([
                'ordinary' => __('Ordinary', 'foreningsplugin'),
                'youth' => __('Youth', 'foreningsplugin'),
                'family' => __('Family', 'foreningsplugin'),
            ] as $slug => $label) {
                echo '<option value="' . esc_attr($slug) . '">' . esc_html($label) . '</option>';
            }
            echo '</select></label></p>';
            self::field('birth_date', __('Birth date', 'foreningsplugin'), 'date', false);
            self::field('started_on', __('Start date', 'foreningsplugin'), 'date', true);
            submit_button(__('Save person', 'foreningsplugin'));
            echo '</form>';
            echo '<h2>' . esc_html__('Import', 'foreningsplugin') . '</h2>';
            echo '<p>' . esc_html__('The file is UTF-8 and semicolon-separated, with one row per membership period. A membership number that already exists is left unchanged. The import does not link a WordPress account and does not rename a person who already exists.', 'foreningsplugin') . '</p>';
            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_import_members">';
            wp_nonce_field('assoc_import_members');
            echo '<p><input type="file" name="member_csv" accept=".csv,text/csv" required></p>';
            submit_button(__('Import members', 'foreningsplugin'));
            echo '</form>';
        }

        if (current_user_can(Capabilities::EXPORT_MEMBERS)) {
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

        if ($canEdit) {
            echo '<h2>' . esc_html__('Company membership', 'foreningsplugin') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_register_company">';
            wp_nonce_field('assoc_register_company');
            self::field('organization_name', __('Organization name', 'foreningsplugin'), 'text', true);
            self::field('organization_number', __('Organization number', 'foreningsplugin'), 'text', false);
            echo '<p>' . esc_html__('The organization number is checked for format and checksum only. It is not checked against a company register.', 'foreningsplugin') . '</p>';
            self::field('email', __('Email', 'foreningsplugin'), 'email', false);
            self::field('postal_address', __('Postal address', 'foreningsplugin'), 'text', false);
            self::field('membership_number', __('Membership number', 'foreningsplugin'), 'text', true);
            self::field('started_on', __('Start date', 'foreningsplugin'), 'date', true);
            submit_button(__('Save company membership', 'foreningsplugin'));
            echo '</form>';
            echo '<h2>' . esc_html__('Family participant', 'foreningsplugin') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_add_family_participant">';
            wp_nonce_field('assoc_add_family_participant');
            echo '<p>' . esc_html__('Choose an existing person, or leave that choice empty and enter a new person. The plugin does not merge people automatically.', 'foreningsplugin') . '</p>';
            echo '<p><label>' . esc_html__('Existing person', 'foreningsplugin') . ' <select name="person_id">';
            echo '<option value="0">' . esc_html__('New person', 'foreningsplugin') . '</option>';

            foreach ($records as $record) {
                $existing = $record->person();
                $existingId = (int) $existing->id();
                $label = $existing->firstName() . ' ' . $existing->lastName();
                $number = $record->membershipNumber();

                if ($number !== '') {
                    $label .= ' (' . $number . ')';
                }

                echo '<option value="' . esc_attr((string) $existingId) . '">' . esc_html($label) . '</option>';
            }

            echo '</select></label></p>';
            self::field('membership_id', __('Membership id', 'foreningsplugin'), 'number', true);
            self::field('started_on', __('Start date', 'foreningsplugin'), 'date', true);
            self::field('first_name', __('First name', 'foreningsplugin'), 'text', false);
            self::field('last_name', __('Last name', 'foreningsplugin'), 'text', false);
            self::field('email', __('Email', 'foreningsplugin'), 'email', false);
            self::field('birth_date', __('Birth date', 'foreningsplugin'), 'date', false);
            submit_button(__('Add participant', 'foreningsplugin'));
            echo '</form>';
        }

        echo '<h2>' . esc_html__('People', 'foreningsplugin') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Name', 'foreningsplugin'), __('Email', 'foreningsplugin'), __('Membership number', 'foreningsplugin'), __('Status', 'foreningsplugin'), __('Period', 'foreningsplugin')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        if ($canEdit) {
            echo '<th>' . esc_html__('Action', 'foreningsplugin') . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($records === []) {
            echo '<tr><td colspan="6">' . esc_html__('No people yet.', 'foreningsplugin') . '</td></tr>';
        }

        foreach ($records as $record) {
            $person = $record->person();
            $membership = $record->membership();
            $personId = (int) $person->id();
            echo '<tr>';
            echo '<td>' . esc_html($person->firstName() . ' ' . $person->lastName());
            $mask = WordpressPeople::identity()->masked($personId);
            if ($mask !== null) {
                echo '<br><code>' . esc_html($mask) . '</code>';
            }
            echo '</td>';
            echo '<td>' . esc_html($person->email()) . '</td>';
            echo '<td>' . esc_html($record->membershipNumber()) . '</td>';
            echo '<td>' . esc_html(self::statusLabel($person->status()->value, $membership?->status())) . '</td>';
            $period = $membership === null ? '' : $membership->startedOn()->iso();
            if ($membership !== null && $membership->endedOn() !== null) {
                $period .= ' – ' . $membership->endedOn()->iso();
            }
            echo '<td>' . esc_html($period) . '</td>';

            if ($canEdit) {
                echo '<td>';
                if ($membership !== null && $membership->status() !== MembershipStatus::Ended) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    echo '<input type="hidden" name="action" value="assoc_end_membership">';
                    echo '<input type="hidden" name="membership_id" value="' . esc_attr((string) $membership->id()) . '">';
                    wp_nonce_field('assoc_end_membership');
                    echo '<input type="date" name="ended_on" required> ';
                    submit_button(__('End membership', 'foreningsplugin'), 'secondary', 'submit', false);
                    echo '</form>';
                }
                if ($person->status() !== PersonStatus::Deceased && $record->account() !== null && $membership !== null && $membership->status() === MembershipStatus::Ended) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    echo '<input type="hidden" name="action" value="assoc_add_membership">';
                    echo '<input type="hidden" name="membership_id" value="' . esc_attr((string) $record->account()->id()) . '">';
                    wp_nonce_field('assoc_add_membership');
                    echo '<input type="date" name="started_on" required> ';
                    submit_button(__('New period', 'foreningsplugin'), 'secondary', 'submit', false);
                    echo '</form>';
                }
                if ($person->status() !== PersonStatus::Deceased) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    echo '<input type="hidden" name="action" value="assoc_mark_deceased">';
                    echo '<input type="hidden" name="person_id" value="' . esc_attr((string) $personId) . '">';
                    wp_nonce_field('assoc_mark_deceased');
                    echo '<input type="date" name="deceased_on" required> ';
                    submit_button(__('Mark as deceased', 'foreningsplugin'), 'delete', 'submit', false);
                    echo '</form>';
                }
                if (current_user_can(Capabilities::EDIT_PERSONAL_IDENTITY_NUMBERS)) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    echo '<input type="hidden" name="action" value="assoc_store_identity">';
                    echo '<input type="hidden" name="person_id" value="' . esc_attr((string) $personId) . '">';
                    wp_nonce_field('assoc_store_identity');
                    echo '<input type="text" name="personal_identity_number" placeholder="' . esc_attr__('Personal identity number', 'foreningsplugin') . '" autocomplete="off"> ';
                    echo '<span class="description">' . esc_html__('Checked for format and checksum only, including coordination numbers. Not checked against an external register.', 'foreningsplugin') . '</span> ';
                    echo '<input type="text" name="purpose" placeholder="' . esc_attr__('Purpose', 'foreningsplugin') . '" required> ';
                    echo '<input type="text" name="basis_note" placeholder="' . esc_attr__('Basis note', 'foreningsplugin') . '"> ';
                    echo '<input type="date" name="collected_on" required> ';
                    submit_button(__('Save identity record', 'foreningsplugin'), 'secondary', 'submit', false);
                    echo '</form>';
                }
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="assoc_add_guardian">';
                echo '<input type="hidden" name="child_person_id" value="' . esc_attr((string) $personId) . '">';
                wp_nonce_field('assoc_add_guardian');
                echo '<input type="text" name="guardian_first_name" placeholder="' . esc_attr__('Guardian first name', 'foreningsplugin') . '"> ';
                echo '<input type="text" name="guardian_last_name" placeholder="' . esc_attr__('Guardian last name', 'foreningsplugin') . '"> ';
                echo '<input type="text" name="relationship" placeholder="' . esc_attr__('Relationship', 'foreningsplugin') . '"> ';
                submit_button(__('Add guardian', 'foreningsplugin'), 'secondary', 'submit', false);
                echo '</form>';
                echo '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private static function guardEdit(string $nonce): void
    {
        if (! current_user_can(Capabilities::EDIT_MEMBERS)) {
            wp_die(esc_html__('You do not have permission to change members.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    /**
     * @param array<string, string> $extra
     */
    private static function redirect(string $notice, array $extra = []): void
    {
        wp_safe_redirect(add_query_arg(array_merge([
            'page' => 'foreningsplugin-members',
            'assoc_notice' => $notice,
        ], $extra), admin_url('admin.php')));
        exit;
    }

    private static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';
        $messages = [
            'created' => __('The person is saved.', 'foreningsplugin'),
            'renewed' => __('A new membership period is saved. The earlier period remains.', 'foreningsplugin'),
            'identity_saved' => __('The personal identity record is saved.', 'foreningsplugin'),
            'identity_removed' => __('The personal identity record is removed. Membership history remains.', 'foreningsplugin'),
            'guardian_saved' => __('The guardian relationship is saved.', 'foreningsplugin'),
            'approval_saved' => __('The guardian approval is saved. It records what the association says occurred.', 'foreningsplugin'),
            'participant_saved' => __('The participant is saved on the existing membership.', 'foreningsplugin'),
            'ended' => __('The membership is ended. The person remains.', 'foreningsplugin'),
            'deceased' => __('The person is marked as deceased and open memberships are ended.', 'foreningsplugin'),
            'duplicate_number' => __('The membership number is already used.', 'foreningsplugin'),
            'overlap' => __('The membership periods overlap.', 'foreningsplugin'),
            'already_ended' => __('The membership is already ended.', 'foreningsplugin'),
            'assignment' => __('An open board assignment does not fit the date, so nothing changed.', 'foreningsplugin'),
            'invalid' => __('Check the details and try again.', 'foreningsplugin'),
            'deceased_period' => __('A deceased person cannot receive a new membership period.', 'foreningsplugin'),
            'import_invalid' => __('The file must be UTF-8 with the expected columns, separated by semicolons.', 'foreningsplugin'),
            'import_too_large' => __('The file is larger than 2 MB.', 'foreningsplugin'),
        ];

        if ($notice === 'import_done') {
            $created = isset($_GET['assoc_created']) ? absint($_GET['assoc_created']) : 0;
            $skipped = isset($_GET['assoc_skipped']) ? absint($_GET['assoc_skipped']) : 0;
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
                /* translators: 1: created memberships, 2: skipped memberships */
                __('The import created %1$d membership periods and left %2$d unchanged.', 'foreningsplugin'),
                $created,
                $skipped
            )) . '</p></div>';
            $stored = get_transient('assoc_member_import_' . get_current_user_id());
            delete_transient('assoc_member_import_' . get_current_user_id());

            if (is_array($stored)) {
                foreach ($stored as $error) {
                    if (is_string($error)) {
                        echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
                    }
                }
            }

            return;
        }

        if (! isset($messages[$notice])) {
            return;
        }

        $class = in_array($notice, ['created', 'ended', 'deceased', 'renewed', 'identity_saved', 'identity_removed', 'guardian_saved', 'approval_saved', 'participant_saved'], true) ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($messages[$notice]) . '</p></div>';
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

    private static function statusLabel(string $personStatus, ?MembershipStatus $membershipStatus): string
    {
        if ($personStatus === PersonStatus::Deceased->value) {
            return __('Deceased', 'foreningsplugin');
        }

        return match ($membershipStatus) {
            MembershipStatus::Active => __('Active', 'foreningsplugin'),
            MembershipStatus::Pending => __('Pending', 'foreningsplugin'),
            MembershipStatus::Dormant => __('Dormant', 'foreningsplugin'),
            MembershipStatus::Ended => __('Ended', 'foreningsplugin'),
            default => __('No membership', 'foreningsplugin'),
        };
    }
}
