<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Account\AccountOutcome;
use Foreningssystem\Application\People\BoardCoverageNotice;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardRuleException;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipKind;
use Foreningssystem\Domain\Membership\MembershipRuleException;

final class MembersPage
{
    public static function registerPerson(): void
    {
        self::guardEdit('assoc_register_person');

        try {
            $kind = self::text('membership_kind');
            $birth = self::text('birth_date');
            $personId = WordpressPeople::service()->register(
                self::text('first_name'),
                self::text('last_name'),
                self::text('email'),
                self::text('membership_number'),
                $kind === '' ? 'ordinary' : $kind,
                AssociationDate::fromIso(self::text('started_on')),
                $birth === '' ? null : AssociationDate::fromIso($birth),
                AssociationDate::fromIso(wp_date('Y-m-d'))
            );
            WordpressMemberAccounts::provisionPerson($personId);
            self::redirect('created', ['assoc_person' => (string) $personId]);
        } catch (MembershipRuleException | \InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::noticeCode($error));
        }
    }

    public static function addMembership(): void
    {
        self::guardEdit('assoc_add_membership');

        try {
            $membershipId = self::integer('membership_id');
            WordpressPeople::service()->addPeriod(
                $membershipId,
                AssociationDate::fromIso(self::text('started_on'))
            );
            WordpressMemberAccounts::provisionMembership($membershipId);
            self::redirect('renewed', self::returnArgs());
        } catch (MembershipRuleException | \InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::noticeCode($error), self::returnArgs());
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
            self::redirect('company_saved', ['assoc_number' => self::text('membership_number')]);
        } catch (MembershipRuleException | \InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::noticeCode($error));
        }
    }

    public static function addFamilyParticipant(): void
    {
        self::guardEdit('assoc_add_family_participant');

        try {
            WordpressPeople::service()->requireKind(self::integer('membership_id'), MembershipKind::Family);
            $startedOn = AssociationDate::fromIso(self::text('started_on'));
            $personId = self::integer('person_id');

            if (self::text('participant_role') === 'contact') {
                throw new \InvalidArgumentException('A family participant is a member.');
            }

            $primary = self::text('primary_contact') === '1';

            if ($personId > 0) {
                WordpressPeople::service()->addParticipant(
                    self::integer('membership_id'),
                    $personId,
                    \Foreningssystem\Domain\Membership\ParticipantRole::Member,
                    $primary,
                    $startedOn
                );
            } else {
                $personId = WordpressPeople::service()->addPersonToMembership(
                    self::integer('membership_id'),
                    self::text('first_name'),
                    self::text('last_name'),
                    self::text('email'),
                    self::text('birth_date') === '' ? null : AssociationDate::fromIso(self::text('birth_date')),
                    $startedOn,
                    \Foreningssystem\Domain\Membership\ParticipantRole::Member,
                    $primary,
                    AssociationDate::fromIso(wp_date('Y-m-d'))
                );
            }

            WordpressMemberAccounts::provisionPerson($personId);
            self::redirect('participant_saved', self::returnArgs());
        } catch (MembershipRuleException | \InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::noticeCode($error), self::returnArgs());
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
            self::redirect('identity_saved', self::returnArgs());
        } catch (\InvalidArgumentException $error) {
            self::redirect(self::noticeCode($error), self::returnArgs());
        }
    }

    public static function removeIdentity(): void
    {
        if (! current_user_can(Capabilities::EDIT_PERSONAL_IDENTITY_NUMBERS)) {
            wp_die(esc_html__('You do not have permission to change personal identity numbers.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer('assoc_remove_identity');

        if (! self::confirmed()) {
            self::redirect('confirm', self::returnArgs());
        }

        WordpressPeople::identity()->remove(self::integer('person_id'), get_current_user_id());
        self::redirect('identity_removed', self::returnArgs());
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

            $started = self::text('started_on');
            WordpressPeople::guardians()->relate(
                self::integer('child_person_id'),
                $guardianId,
                self::text('relationship') === '' ? 'guardian' : self::text('relationship'),
                $started === '' ? null : AssociationDate::fromIso($started)
            );
            self::redirect('guardian_saved', self::returnArgs());
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::noticeCode($error), self::returnArgs());
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
            self::redirect('approval_saved', self::returnArgs());
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect('invalid', self::returnArgs());
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

        if (! self::confirmed()) {
            self::redirect('confirm', self::returnArgs());
        }

        try {
            $notices = WordpressPeople::service()->endMembership(
                self::integer('membership_id'),
                AssociationDate::fromIso(self::text('ended_on'))
            );
            self::rememberBoardEffects($notices);
            self::redirect('ended', self::returnArgs());
        } catch (BoardRuleException | MembershipRuleException | \InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::noticeCode($error), self::returnArgs());
        }
    }

    public static function endParticipation(): void
    {
        self::guardEdit('assoc_end_participation');

        if (! self::confirmed()) {
            self::redirect('confirm', self::returnArgs());
        }

        try {
            $notices = WordpressPeople::service()->endParticipation(
                self::integer('membership_id'),
                self::integer('person_id'),
                AssociationDate::fromIso(self::text('ended_on'))
            );
            self::rememberBoardEffects($notices);
            self::redirect('participation_ended', self::returnArgs());
        } catch (BoardRuleException | MembershipRuleException | \InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::noticeCode($error), self::returnArgs());
        }
    }

    public static function openExisting(): void
    {
        self::guardEdit('assoc_open_membership');
        $kind = self::text('membership_kind');

        if (! in_array($kind, ['ordinary', 'youth', 'family'], true)) {
            self::redirect('invalid');
        }

        try {
            $personId = self::integer('person_id');

            if ($personId < 1) {
                throw new \InvalidArgumentException('Choose a person.');
            }

            WordpressPeople::service()->openForExistingPerson(
                $personId,
                self::text('membership_number'),
                $kind,
                AssociationDate::fromIso(self::text('started_on'))
            );
            WordpressMemberAccounts::provisionPerson($personId);
            self::redirect('created', ['assoc_person' => (string) $personId]);
        } catch (MembershipRuleException | \InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::noticeCode($error));
        }
    }

    public static function updatePerson(): void
    {
        self::guardEdit('assoc_update_person');
        $personId = self::integer('person_id');
        $birth = self::text('birth_date');

        try {
            $birthDate = $birth === '' ? null : AssociationDate::fromIso($birth);

            if ($birthDate instanceof AssociationDate && WordpressPeople::identity()->conflictsWithBirthDate($personId, $birthDate)) {
                self::redirect('birth_mismatch', ['assoc_person' => (string) $personId]);
            }

            WordpressPeople::service()->updateContact(
                $personId,
                self::text('first_name'),
                self::text('last_name'),
                self::text('email'),
                $birthDate,
                AssociationDate::fromIso(wp_date('Y-m-d'))
            );
            WordpressMemberAccounts::provisionPerson($personId);
            self::redirect('person_saved', ['assoc_person' => (string) $personId]);
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::noticeCode($error), ['assoc_person' => (string) $personId]);
        }
    }

    public static function addCompanyContact(): void
    {
        self::guardEdit('assoc_add_company_contact');

        try {
            WordpressPeople::service()->requireKind(self::integer('membership_id'), MembershipKind::Company);
            $personId = self::integer('person_id');

            if ($personId < 1) {
                $personId = WordpressPeople::service()->rememberPerson(
                    self::text('first_name'),
                    self::text('last_name'),
                    self::text('email'),
                    null,
                    AssociationDate::fromIso(wp_date('Y-m-d'))
                );
            }

            WordpressPeople::service()->addParticipant(
                self::integer('membership_id'),
                $personId,
                \Foreningssystem\Domain\Membership\ParticipantRole::Contact,
                false,
                AssociationDate::fromIso(self::text('started_on'))
            );
            self::redirect('contact_saved', self::returnArgs());
        } catch (MembershipRuleException | \InvalidArgumentException | \RuntimeException $error) {
            self::redirect(self::noticeCode($error), self::returnArgs());
        }
    }

    public static function endGuardian(): void
    {
        self::guardEdit('assoc_end_guardian');

        if (! self::confirmed()) {
            self::redirect('confirm', self::returnArgs());
        }

        try {
            WordpressPeople::guardians()->endRelationship(
                self::integer('child_person_id'),
                self::integer('relationship_id'),
                AssociationDate::fromIso(self::text('ended_on'))
            );
            self::redirect('guardian_ended', self::returnArgs());
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect('invalid', self::returnArgs());
        }
    }

    public static function withdrawGuardianApproval(): void
    {
        self::guardEdit('assoc_withdraw_guardian_approval');

        if (! self::confirmed()) {
            self::redirect('confirm', self::returnArgs());
        }

        try {
            WordpressPeople::guardians()->withdraw(
                self::integer('approval_id'),
                new \DateTimeImmutable(self::text('withdrawn_on') . ' 00:00:00'),
                get_current_user_id()
            );
            self::redirect('approval_withdrawn', self::returnArgs());
        } catch (\InvalidArgumentException | \RuntimeException) {
            self::redirect('invalid', self::returnArgs());
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

        if (! self::confirmed()) {
            self::redirect('confirm', self::returnArgs());
        }

        try {
            WordpressPeople::service()->markDeceased(
                self::integer('person_id'),
                AssociationDate::fromIso(self::text('deceased_on'))
            );
            self::redirect('deceased', self::returnArgs());
        } catch (BoardRuleException | \InvalidArgumentException | MembershipRuleException | \RuntimeException $error) {
            self::redirect(self::noticeCode($error), self::returnArgs());
        }
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::VIEW_MEMBERS)) {
            wp_die(esc_html__('You do not have permission to view members.', 'foreningsplugin'));
        }

        MembersScreen::render(current_user_can(Capabilities::EDIT_MEMBERS));
    }

    /**
     * @param list<BoardCoverageNotice> $notices
     */
    public static function rememberBoardEffects(array $notices): void
    {
        $roles = [];

        foreach ((new WpdbBoardRoleRepository())->all() as $role) {
            if ($role->id() !== null) {
                $roles[$role->id()] = $role->name();
            }
        }

        $lines = [];

        foreach ($notices as $notice) {
            $role = $roles[$notice->roleId()] ?? '';

            if ($notice->remainsActive()) {
                $lines[] = $role === ''
                    ? __('Board assignment remains active because the person has continuous membership coverage.', 'foreningsplugin')
                    : sprintf(
                        /* translators: %s: board role name */
                        __('%s assignment remains active because the person has continuous membership coverage.', 'foreningsplugin'),
                        $role
                    );
                continue;
            }

            $lines[] = $role === ''
                ? sprintf(
                    /* translators: %s: end date */
                    __('A board assignment now ends %s.', 'foreningsplugin'),
                    (string) $notice->endedOn()
                )
                : sprintf(
                    /* translators: 1: board role name, 2: end date */
                    __('%1$s assignment now ends %2$s.', 'foreningsplugin'),
                    $role,
                    (string) $notice->endedOn()
                );
        }

        if ($lines !== []) {
            set_transient('assoc_board_effect_' . get_current_user_id(), $lines, MINUTE_IN_SECONDS);
        }
    }

    /**
     * @return list<string>
     */
    public static function boardEffectLines(): array
    {
        $stored = get_transient('assoc_board_effect_' . get_current_user_id());
        delete_transient('assoc_board_effect_' . get_current_user_id());

        if (! is_array($stored)) {
            return [];
        }

        $lines = [];

        foreach ($stored as $line) {
            if (is_string($line) && $line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    private static function returnArgs(): array
    {
        $args = [];
        $personId = self::integer('return_person');

        if ($personId < 1) {
            $personId = self::integer('person_id');
        }

        if ($personId < 1) {
            $personId = self::integer('child_person_id');
        }

        if ($personId > 0) {
            $args['assoc_person'] = (string) $personId;
        }

        $number = self::text('return_number');

        if ($number !== '') {
            $args['assoc_number'] = $number;
        }

        return $args;
    }

    private static function confirmed(): bool
    {
        return self::text('confirm') === '1';
    }

    private static function noticeCode(\Throwable $error): string
    {
        return match ($error->getMessage()) {
            'Membership number is already used.' => 'duplicate_number',
            'The organization number is already used.' => 'duplicate_organization',
            'Membership periods cannot overlap.', 'The person already participates in this membership.' => 'overlap',
            'A company contact is not an individual member.' => 'company_contact',
            'A deceased person cannot start a membership.', 'A deceased person cannot receive a new membership period.' => 'deceased_period',
            'The membership does not cover this assignment.', 'An assignment cannot end before it starts.', 'An open board assignment does not fit the date, so nothing changed.' => 'assignment',
            'A membership cannot end before it starts.', 'Participation cannot end before it starts.', 'The assignment cannot end before the start date.' => 'before_start',
            'The membership is already ended.', 'Membership is already ended.' => 'already_ended',
            'The personal identity number does not match the birth date.' => 'birth_mismatch',
            'Person was not found.', 'Membership was not found.', 'The guardian relationship was not found.' => 'not_found',
            'The person has no open participation in this membership.' => 'no_open',
            'Choose a person.' => 'choose_person',
            'A family participant is a member.' => 'family_role',
            'This guardian relationship already covers that time.' => 'guardian_overlap',
            'This action is only available for family memberships.' => 'family_only',
            'Company contacts can only be added to company memberships.' => 'company_only',
            'An ordinary or youth membership has one member.' => 'single_member',
            'Add a birth date before starting a youth membership.' => 'youth_birth',
            default => 'invalid',
        };
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

    public static function createMemberAccount(): void
    {
        self::guardEdit('assoc_create_member_account');
        $personId = self::integer('person_id');
        $result = WordpressMemberAccounts::service()->provision($personId, AssociationDate::fromIso(wp_date('Y-m-d')));
        $notice = match ($result->outcome) {
            AccountOutcome::Created => 'account_created',
            AccountOutcome::AlreadyLinked => 'account_linked',
            AccountOutcome::KnownMinor => 'minor_account',
            AccountOutcome::Failed => 'account_failed',
            default => 'account_unchanged',
        };
        self::redirect($notice, ['assoc_person' => (string) $personId]);
    }

    public static function findMemberAccount(): void
    {
        self::guardEdit('assoc_find_member_account');
        $personId = self::integer('person_id');
        $userId = WordpressMemberAccounts::findUser(self::text('account_login'));

        if ($userId === null) {
            self::redirect('account_not_found', ['assoc_person' => (string) $personId]);
        }

        self::redirect('account_review', [
            'assoc_person' => (string) $personId,
            'assoc_account_user' => (string) $userId,
        ]);
    }

    public static function linkMemberAccount(): void
    {
        self::guardEdit('assoc_link_member_account');
        $personId = self::integer('person_id');

        if (! self::confirmed()) {
            self::redirect('confirm', ['assoc_person' => (string) $personId]);
        }

        $result = WordpressMemberAccounts::service()->linkExisting(
            $personId,
            self::integer('wp_user_id'),
            AssociationDate::fromIso(wp_date('Y-m-d'))
        );
        $notice = match ($result->outcome) {
            AccountOutcome::Linked, AccountOutcome::AlreadyLinked => 'account_linked',
            AccountOutcome::UserTaken => 'account_taken',
            AccountOutcome::UnlinkFirst => 'account_unlink_first',
            AccountOutcome::MinorLinkRefused => 'minor_account',
            AccountOutcome::UserNotFound => 'account_not_found',
            default => 'account_failed',
        };
        self::redirect($notice, ['assoc_person' => (string) $personId]);
    }

    public static function unlinkMemberAccount(): void
    {
        self::guardEdit('assoc_unlink_member_account');
        $personId = self::integer('person_id');

        if (! self::confirmed()) {
            self::redirect('confirm', ['assoc_person' => (string) $personId]);
        }

        $result = WordpressMemberAccounts::service()->unlink($personId);
        self::redirect($result->outcome === AccountOutcome::Unlinked ? 'account_unlinked' : 'account_failed', [
            'assoc_person' => (string) $personId,
        ]);
    }

    public static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';
        $messages = [
            'created' => __('The membership is saved.', 'foreningsplugin'),
            'company_saved' => __('The company membership is saved. A company contact is not an individual member.', 'foreningsplugin'),
            'renewed' => __('A new membership period is saved. The earlier period remains.', 'foreningsplugin'),
            'person_saved' => __('The person is saved.', 'foreningsplugin'),
            'identity_saved' => __('The personal identity record is saved.', 'foreningsplugin'),
            'identity_removed' => __('The personal identity record is removed. Membership history remains.', 'foreningsplugin'),
            'guardian_saved' => __('The guardian relationship is saved. It is not inferred from name, family or address.', 'foreningsplugin'),
            'guardian_ended' => __('The guardian relationship is ended. The earlier record remains.', 'foreningsplugin'),
            'approval_saved' => __('The guardian approval is saved. It records what the association says occurred. It does not prove legal validity.', 'foreningsplugin'),
            'approval_withdrawn' => __('The guardian approval is marked as withdrawn. The record remains.', 'foreningsplugin'),
            'participant_saved' => __('The participant is saved. The earlier participation history remains.', 'foreningsplugin'),
            'contact_saved' => __('The company contact is saved. This does not make the person an individual member.', 'foreningsplugin'),
            'ended' => __('The membership is ended. Membership history is retained.', 'foreningsplugin'),
            'participation_ended' => __('The participation is ended. The earlier participation history remains.', 'foreningsplugin'),
            'deceased' => __('The person is marked as deceased and open memberships are ended. The history remains.', 'foreningsplugin'),
            'duplicate_number' => __('That membership number is already in use.', 'foreningsplugin'),
            'duplicate_organization' => __('That organization number is already in use.', 'foreningsplugin'),
            'overlap' => __('This person already has overlapping active membership coverage.', 'foreningsplugin'),
            'company_contact' => __('A company contact is not an individual member.', 'foreningsplugin'),
            'already_ended' => __('The membership is already ended.', 'foreningsplugin'),
            'assignment' => __('The date does not cover a board assignment, so nothing changed.', 'foreningsplugin'),
            'before_start' => __('The end date cannot be before the start date.', 'foreningsplugin'),
            'birth_mismatch' => __('The birth date does not match the recorded personal identity number.', 'foreningsplugin'),
            'not_found' => __('That person or membership could not be found.', 'foreningsplugin'),
            'no_open' => __('There is no open participation to end.', 'foreningsplugin'),
            'choose_person' => __('Choose an existing person.', 'foreningsplugin'),
            'family_role' => __('A family participant is a member.', 'foreningsplugin'),
            'guardian_overlap' => __('This guardian relationship already covers that time.', 'foreningsplugin'),
            'family_only' => __('This action is only available for family memberships.', 'foreningsplugin'),
            'company_only' => __('Company contacts can only be added to company memberships.', 'foreningsplugin'),
            'single_member' => __('An ordinary or youth membership has one member.', 'foreningsplugin'),
            'youth_birth' => __('Add a birth date before starting a youth membership.', 'foreningsplugin'),
            'confirm' => __('Confirm the action before it is saved.', 'foreningsplugin'),
            'invalid' => __('Check the details and try again.', 'foreningsplugin'),
            'deceased_period' => __('A deceased person cannot receive a new membership period.', 'foreningsplugin'),
            'import_invalid' => __('The file must be UTF-8 with the expected columns, separated by semicolons.', 'foreningsplugin'),
            'import_too_large' => __('The file is larger than 2 MB.', 'foreningsplugin'),
            'account_created' => __('The WordPress account is created. WordPress sends the password setup message.', 'foreningsplugin'),
            'account_linked' => __('The WordPress account is linked to this member.', 'foreningsplugin'),
            'account_unlinked' => __('The WordPress account is unlinked. The WordPress user was not deleted.', 'foreningsplugin'),
            'account_unchanged' => __('No WordPress account was created.', 'foreningsplugin'),
            'account_review' => __('Confirm the WordPress account before linking it.', 'foreningsplugin'),
            'account_not_found' => __('No WordPress account matches that username or email.', 'foreningsplugin'),
            'account_taken' => __('That WordPress account is already linked to another person.', 'foreningsplugin'),
            'account_unlink_first' => __('Unlink the current WordPress account before linking a different one.', 'foreningsplugin'),
            'account_failed' => __('The WordPress account could not be created.', 'foreningsplugin'),
            'minor_account' => __('No account is created automatically for members under 18.', 'foreningsplugin'),
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

        $success = [
            'created',
            'company_saved',
            'ended',
            'participation_ended',
            'deceased',
            'renewed',
            'person_saved',
            'identity_saved',
            'identity_removed',
            'guardian_saved',
            'guardian_ended',
            'approval_saved',
            'approval_withdrawn',
            'participant_saved',
            'contact_saved',
            'account_created',
            'account_linked',
            'account_unlinked',
            'account_review',
        ];
        $class = in_array($notice, $success, true) ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($messages[$notice]) . '</p>';

        foreach (self::boardEffectLines() as $line) {
            echo '<p>' . esc_html($line) . '</p>';
        }

        echo '</div>';
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
