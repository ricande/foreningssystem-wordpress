<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\Settings\StructureRuleException;
use Foreningssystem\Application\Setup\SetupStep;
use Foreningssystem\Application\Setup\SetupWizard;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Access\RoleBundles;
use Foreningssystem\Domain\Association\AssociationProfile;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Privacy\RetentionPeriod;
use InvalidArgumentException;
use RuntimeException;

final class SetupPage
{
    public const PAGE = 'foreningsplugin-setup';

    public static function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            wp_die(esc_html__('You do not have permission to run association setup.', 'foreningsplugin'), '', ['response' => 403]);
        }

        $wizard = self::wizard();
        $step = $wizard->resolveStep(isset($_GET['step']) ? sanitize_key((string) $_GET['step']) : null);
        $profile = WordpressAssociationProfile::load();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Association setup', 'foreningsplugin') . '</h1>';
        self::progress($step);
        self::notice();

        match ($step) {
            SetupStep::WELCOME => self::welcome(),
            SetupStep::ASSOCIATION => self::association($profile),
            SetupStep::MEMBERSHIP => self::membership(),
            SetupStep::BOARD => self::board(),
            SetupStep::MEETINGS => self::meetings(),
            SetupStep::MINUTES => self::minutes(),
            SetupStep::PRIVACY => self::privacy(),
            SetupStep::COMPLETE => self::complete($profile, $wizard),
            default => self::welcome(),
        };

        echo '</div>';
    }

    public static function start(): void
    {
        self::guard('assoc_setup_start');
        self::wizard()->advanceTo(SetupStep::ASSOCIATION);
        self::redirect(SetupStep::ASSOCIATION);
    }

    public static function back(): void
    {
        self::guard('assoc_setup_back');
        $from = isset($_POST['from_step']) ? sanitize_key((string) $_POST['from_step']) : null;
        $previous = self::wizard()->goBack($from);
        self::redirect($previous);
    }

    public static function skip(): void
    {
        self::guard('assoc_setup_skip');
        $from = isset($_POST['from_step']) ? sanitize_key((string) $_POST['from_step']) : null;
        $next = self::wizard()->goNext($from);
        self::redirect($next);
    }

    public static function saveAssociation(): void
    {
        self::guard('assoc_setup_save_association');

        try {
            $profile = self::postedProfile();
            WordpressAssociationProfile::save($profile);
            self::wizard()->assertAssociationNameForProgress($profile);
            self::wizard()->goNext(SetupStep::ASSOCIATION);
            self::redirect(SetupStep::MEMBERSHIP);
        } catch (NotAllowed) {
            self::denied();
        } catch (InvalidArgumentException) {
            self::redirect(SetupStep::ASSOCIATION, 'association_invalid');
        }
    }

    public static function addBoardRole(): void
    {
        self::guard('assoc_setup_add_board_role');
        $name = self::text('board_role_name');
        $allowsMultiple = isset($_POST['allows_multiple']) && (string) $_POST['allows_multiple'] === '1';

        try {
            WordpressAssociationSettings::boardRoles()->create($name, $allowsMultiple);
            self::redirect(SetupStep::BOARD, 'role_added');
        } catch (NotAllowed) {
            self::denied();
        } catch (StructureRuleException | RuntimeException) {
            self::redirect(SetupStep::BOARD, 'role_failed');
        }
    }

    public static function addMeetingType(): void
    {
        self::guard('assoc_setup_add_meeting_type');

        try {
            WordpressAssociationSettings::meetingTypes()->create(self::text('meeting_type_name'));
            self::redirect(SetupStep::MEETINGS, 'type_added');
        } catch (NotAllowed) {
            self::denied();
        } catch (StructureRuleException | RuntimeException) {
            self::redirect(SetupStep::MEETINGS, 'type_failed');
        }
    }

    public static function saveMinutes(): void
    {
        self::guard('assoc_setup_save_minutes');
        $lockRoles = self::postedRoles('lock_roles');
        $publishRoles = self::postedRoles('publish_roles');

        try {
            WordpressMinutesLock::update($lockRoles);
            WordpressMinutesPublish::update($publishRoles);
            self::wizard()->goNext(SetupStep::MINUTES);
            self::redirect(SetupStep::PRIVACY);
        } catch (NotAllowed) {
            self::denied();
        } catch (InvalidArgumentException) {
            self::redirect(SetupStep::MINUTES, 'minutes_invalid');
        }
    }

    public static function savePrivacy(): void
    {
        self::guard('assoc_setup_save_privacy');
        $years = isset($_POST['years']) ? (int) $_POST['years'] : 0;

        try {
            WordpressRetention::save(new RetentionPeriod($years));
            self::wizard()->goNext(SetupStep::PRIVACY);
            self::redirect(SetupStep::COMPLETE);
        } catch (NotAllowed) {
            self::denied();
        } catch (InvalidArgumentException) {
            self::redirect(SetupStep::PRIVACY, 'privacy_invalid');
        }
    }

    public static function finish(): void
    {
        self::guard('assoc_setup_finish');
        $profile = WordpressAssociationProfile::load();

        try {
            self::wizard()->finish($profile);
            wp_safe_redirect(add_query_arg([
                'page' => 'foreningsplugin',
                'assoc_notice' => 'setup_complete',
            ], admin_url('admin.php')));
            exit;
        } catch (NotAllowed) {
            self::denied();
        } catch (InvalidArgumentException) {
            self::redirect(SetupStep::COMPLETE, 'finish_name_required');
        }
    }

    private static function welcome(): void
    {
        echo '<h2>' . esc_html__('Welcome', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('This guide helps you configure the association structure: profile, membership kinds, board roles, meeting types, minutes permissions, and retention. It does not add members, board assignments, meetings, documents, decisions, or tasks.', 'foreningsplugin') . '</p>';
        echo '<p>' . esc_html__('You can leave and return later from Association → Get started.', 'foreningsplugin') . '</p>';
        self::actionForm('assoc_setup_start', __('Start setup', 'foreningsplugin'));
    }

    private static function association(AssociationProfile $profile): void
    {
        $year = $profile->membershipYear(AssociationDate::fromIso(wp_date('Y-m-d')));
        echo '<h2>' . esc_html__('Association', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('The association\'s name, contact details, and when the membership year starts. The name is required to continue.', 'foreningsplugin') . '</p>';
        echo '<p>' . esc_html(sprintf(
            /* translators: 1: start date, 2: end date */
            __('Current membership year: %1$s–%2$s', 'foreningsplugin'),
            $year->startedOn()->iso(),
            $year->endedOn()->iso()
        )) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_setup_save_association">';
        wp_nonce_field('assoc_setup_save_association');
        echo '<table class="form-table"><tbody>';
        self::textRow('name', __('Name', 'foreningsplugin'), $profile->name(), true);
        self::textRow('organization_number', __('Organization number', 'foreningsplugin'), $profile->organizationNumber());
        echo '<tr><th scope="row"><label for="assoc-address">' . esc_html__('Address', 'foreningsplugin') . '</label></th><td>';
        echo '<textarea name="address" id="assoc-address" rows="3" cols="40">' . esc_textarea($profile->address()) . '</textarea></td></tr>';
        self::textRow('email', __('Email', 'foreningsplugin'), $profile->email());
        self::textRow('phone', __('Phone', 'foreningsplugin'), $profile->phone());
        echo '<tr><th scope="row"><label for="assoc-language">' . esc_html__('Language', 'foreningsplugin') . '</label></th><td><select name="language" id="assoc-language">';
        echo '<option value="sv"' . selected($profile->language(), AssociationProfile::LANGUAGE_SWEDISH, false) . '>' . esc_html__('Swedish', 'foreningsplugin') . '</option>';
        echo '<option value="en"' . selected($profile->language(), AssociationProfile::LANGUAGE_ENGLISH, false) . '>' . esc_html__('English', 'foreningsplugin') . '</option>';
        echo '</select></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('The membership year starts', 'foreningsplugin') . '</th><td>';
        echo '<label>' . esc_html__('Month', 'foreningsplugin') . ' <select name="membership_year_month">';
        foreach (self::months() as $number => $label) {
            echo '<option value="' . esc_attr((string) $number) . '"' . selected($profile->membershipYearStartMonth(), $number, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> ';
        echo '<label>' . esc_html__('Day', 'foreningsplugin') . ' <input type="number" name="membership_year_day" min="1" max="31" required value="' . esc_attr((string) $profile->membershipYearStartDay()) . '"></label>';
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '<p>';
        echo '<button type="submit">' . esc_html__('Save and continue', 'foreningsplugin') . '</button>';
        echo '</p></form>';
        self::backOnly(SetupStep::ASSOCIATION);
    }

    private static function membership(): void
    {
        echo '<h2>' . esc_html__('Membership', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('The product supports ordinary, youth, family, and company memberships. A Person is not the same as a Membership, and neither is the same as a WordPress account.', 'foreningsplugin') . '</p>';
        echo '<ul>';
        echo '<li>' . esc_html__('Ordinary — an individual adult membership.', 'foreningsplugin') . '</li>';
        echo '<li>' . esc_html__('Youth — an individual membership for a younger person.', 'foreningsplugin') . '</li>';
        echo '<li>' . esc_html__('Family — several people on one membership.', 'foreningsplugin') . '</li>';
        echo '<li>' . esc_html__('Company — an organization membership, not a person.', 'foreningsplugin') . '</li>';
        echo '</ul>';
        echo '<p>' . esc_html__('This step does not create members and does not turn kinds on or off.', 'foreningsplugin') . '</p>';
        self::continueOrSkip(SetupStep::MEMBERSHIP);
    }

    private static function board(): void
    {
        echo '<h2>' . esc_html__('Board', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('Board roles describe the structure. You do not assign people here. Built-in roles keep their meaning. You may add a custom role.', 'foreningsplugin') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Role', 'foreningsplugin') . '</th><th>' . esc_html__('Holders', 'foreningsplugin') . '</th></tr></thead><tbody>';
        foreach (WordpressAssociationSettings::boardRoles()->catalog() as $role) {
            $label = BoardScreen::roleLabel($role->slug(), $role->name());
            echo '<tr><td>' . esc_html($label) . '</td><td>' . esc_html($role->allowsMultiple() ? __('Multiple holders', 'foreningsplugin') : __('One holder', 'foreningsplugin')) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>' . esc_html__('Add board role', 'foreningsplugin') . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_setup_add_board_role">';
        wp_nonce_field('assoc_setup_add_board_role');
        echo '<p><label for="assoc-setup-role">' . esc_html__('Name', 'foreningsplugin') . '</label><br>';
        echo '<input id="assoc-setup-role" name="board_role_name" type="text" required maxlength="100"></p>';
        echo '<p><label><input type="checkbox" name="allows_multiple" value="1"> ';
        echo esc_html__('Allow several people to hold this role at the same time', 'foreningsplugin') . '</label></p>';
        echo '<p><button type="submit">' . esc_html__('Add role', 'foreningsplugin') . '</button></p>';
        echo '</form>';
        self::continueOrSkip(SetupStep::BOARD);
    }

    private static function meetings(): void
    {
        echo '<h2>' . esc_html__('Meetings', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('Meeting types describe how the association meets. You do not create meetings here. Built-in types keep their meaning. You may add a custom type.', 'foreningsplugin') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Meeting type', 'foreningsplugin') . '</th></tr></thead><tbody>';
        foreach (WordpressAssociationSettings::meetingTypes()->catalog() as $type) {
            echo '<tr><td>' . esc_html(MeetingLabels::type($type)) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>' . esc_html__('Add meeting type', 'foreningsplugin') . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_setup_add_meeting_type">';
        wp_nonce_field('assoc_setup_add_meeting_type');
        echo '<p><label for="assoc-setup-type">' . esc_html__('Name', 'foreningsplugin') . '</label><br>';
        echo '<input id="assoc-setup-type" name="meeting_type_name" type="text" required maxlength="100"></p>';
        echo '<p><button type="submit">' . esc_html__('Add meeting type', 'foreningsplugin') . '</button></p>';
        echo '</form>';
        self::continueOrSkip(SetupStep::MEETINGS);
    }

    private static function minutes(): void
    {
        $setting = WordpressAccess::load();
        echo '<h2>' . esc_html__('Minutes and documents', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('Choose which association roles may finalize minutes and which may publish them. Document files stay private; downloads check authorization.', 'foreningsplugin') . '</p>';
        if (PrivateStorageWarning::needsAttention()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__(
                'Protected association files are inside the public web root. Downloads check authorization, but a visitor who can guess the file address can read the file directly unless the web server blocks the directory.',
                'foreningsplugin'
            ) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_setup_save_minutes">';
        wp_nonce_field('assoc_setup_save_minutes');
        echo '<h3>' . esc_html__('Finalize minutes', 'foreningsplugin') . '</h3>';
        foreach (self::roleLabels() as $role => $label) {
            $checked = in_array(Capabilities::FINALIZE_MINUTES, $setting->capabilitiesFor($role), true);
            echo '<p><label><input type="checkbox" name="lock_roles[]" value="' . esc_attr($role) . '"' . checked($checked, true, false) . '> ' . esc_html($label) . '</label></p>';
        }
        echo '<h3>' . esc_html__('Publish minutes', 'foreningsplugin') . '</h3>';
        foreach (self::roleLabels() as $role => $label) {
            $checked = in_array(Capabilities::PUBLISH_MINUTES, $setting->capabilitiesFor($role), true);
            echo '<p><label><input type="checkbox" name="publish_roles[]" value="' . esc_attr($role) . '"' . checked($checked, true, false) . '> ' . esc_html($label) . '</label></p>';
        }
        echo '<p>';
        self::navButtons(SetupStep::MINUTES, true, true);
        echo '<button type="submit">' . esc_html__('Save and continue', 'foreningsplugin') . '</button>';
        echo '</p></form>';
        self::navAuxForms(SetupStep::MINUTES, true, true);
    }

    private static function privacy(): void
    {
        $years = WordpressRetention::load()->years();
        echo '<h2>' . esc_html__('Privacy', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('Contact details are anonymized this many years after the membership has ended. Audit events are removed after the same time. Locked minutes and signed scans are kept. This is not legal advice. Personal identity numbers are never public output.', 'foreningsplugin') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_setup_save_privacy">';
        wp_nonce_field('assoc_setup_save_privacy');
        echo '<p><label>' . esc_html__('Years', 'foreningsplugin') . ' <input type="number" name="years" min="1" max="100" required value="' . esc_attr((string) $years) . '"></label></p>';
        echo '<p>' . esc_html__('Saving here only stores the retention setting. It does not apply erasure now.', 'foreningsplugin') . '</p>';
        echo '<p>';
        self::navButtons(SetupStep::PRIVACY, true, true);
        echo '<button type="submit">' . esc_html__('Save and continue', 'foreningsplugin') . '</button>';
        echo '</p></form>';
        self::navAuxForms(SetupStep::PRIVACY, true, true);
    }

    private static function complete(AssociationProfile $profile, SetupWizard $wizard): void
    {
        echo '<h2>' . esc_html__('Complete', 'foreningsplugin') . '</h2>';
        echo '<p>' . esc_html__('Review the association setup, then finish. Finishing requires a non-empty association name.', 'foreningsplugin') . '</p>';
        echo '<ul>';
        echo '<li>' . esc_html__('Name', 'foreningsplugin') . ': ' . esc_html($profile->name() !== '' ? $profile->name() : __('(missing)', 'foreningsplugin')) . '</li>';
        echo '<li>' . esc_html__('Retention', 'foreningsplugin') . ': ' . esc_html((string) WordpressRetention::load()->years()) . ' ' . esc_html__('years', 'foreningsplugin') . '</li>';
        echo '</ul>';
        if (! $wizard->canFinish($profile)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Enter the association name on the Association step before finishing.', 'foreningsplugin') . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_setup_finish">';
        wp_nonce_field('assoc_setup_finish');
        echo '<p>';
        self::navButtons(SetupStep::COMPLETE, true, false);
        echo '<button type="submit"' . ($wizard->canFinish($profile) ? '' : ' disabled') . '>' . esc_html__('Finish setup', 'foreningsplugin') . '</button>';
        echo '</p></form>';
        self::navAuxForms(SetupStep::COMPLETE, true, false);
    }

    private static function progress(string $step): void
    {
        $all = SetupStep::all();
        $current = SetupStep::index($step) + 1;
        $total = count($all);
        echo '<p>' . esc_html(sprintf(
            /* translators: 1: current step number, 2: total steps */
            __('Step %1$d of %2$d', 'foreningsplugin'),
            $current,
            $total
        )) . '</p>';
        echo '<ol class="assoc-setup-steps">';
        foreach ($all as $candidate) {
            $label = self::stepLabel($candidate);
            if ($candidate === $step) {
                echo '<li><strong>' . esc_html($label) . '</strong></li>';
            } else {
                echo '<li>' . esc_html($label) . '</li>';
            }
        }
        echo '</ol>';
    }

    private static function stepLabel(string $step): string
    {
        return match ($step) {
            SetupStep::WELCOME => __('Welcome', 'foreningsplugin'),
            SetupStep::ASSOCIATION => __('Association', 'foreningsplugin'),
            SetupStep::MEMBERSHIP => __('Membership', 'foreningsplugin'),
            SetupStep::BOARD => __('Board', 'foreningsplugin'),
            SetupStep::MEETINGS => __('Meetings', 'foreningsplugin'),
            SetupStep::MINUTES => __('Minutes and documents', 'foreningsplugin'),
            SetupStep::PRIVACY => __('Privacy', 'foreningsplugin'),
            SetupStep::COMPLETE => __('Complete', 'foreningsplugin'),
            default => __('Welcome', 'foreningsplugin'),
        };
    }

    private static function continueOrSkip(string $step): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        echo '<input type="hidden" name="action" value="assoc_setup_skip">';
        echo '<input type="hidden" name="from_step" value="' . esc_attr($step) . '">';
        wp_nonce_field('assoc_setup_skip');
        echo '<button type="submit">' . esc_html__('Continue', 'foreningsplugin') . '</button> ';
        echo '<button type="submit">' . esc_html__('Skip', 'foreningsplugin') . '</button>';
        echo '</form> ';
        self::backOnly($step);
    }

    private static function backOnly(string $step): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        echo '<input type="hidden" name="action" value="assoc_setup_back">';
        echo '<input type="hidden" name="from_step" value="' . esc_attr($step) . '">';
        wp_nonce_field('assoc_setup_back');
        echo '<button type="submit">' . esc_html__('Back', 'foreningsplugin') . '</button>';
        echo '</form>';
    }

    private static function navButtons(string $step, bool $back, bool $skip): void
    {
        if ($back) {
            echo '<button type="submit" form="assoc-setup-back-' . esc_attr($step) . '">' . esc_html__('Back', 'foreningsplugin') . '</button> ';
        }

        if ($skip) {
            echo '<button type="submit" form="assoc-setup-skip-' . esc_attr($step) . '">' . esc_html__('Skip', 'foreningsplugin') . '</button> ';
        }
    }

    /**
     * Hidden Back/Skip forms must sit outside the step's save form. Nested
     * forms are invalid HTML: browsers close the outer form early, which leaves
     * Save and continue with no form association.
     */
    private static function navAuxForms(string $step, bool $back, bool $skip): void
    {
        if ($back) {
            echo '<form id="assoc-setup-back-' . esc_attr($step) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:none">';
            echo '<input type="hidden" name="action" value="assoc_setup_back">';
            echo '<input type="hidden" name="from_step" value="' . esc_attr($step) . '">';
            wp_nonce_field('assoc_setup_back');
            echo '</form>';
        }

        if ($skip) {
            echo '<form id="assoc-setup-skip-' . esc_attr($step) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:none">';
            echo '<input type="hidden" name="action" value="assoc_setup_skip">';
            echo '<input type="hidden" name="from_step" value="' . esc_attr($step) . '">';
            wp_nonce_field('assoc_setup_skip');
            echo '</form>';
        }
    }

    private static function actionForm(string $action, string $label): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
        echo '<p><button type="submit">' . esc_html($label) . '</button></p>';
        echo '</form>';
    }

    private static function textRow(string $name, string $label, string $value, bool $required = false): void
    {
        echo '<tr><th scope="row"><label for="assoc-' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input type="text" class="regular-text" name="' . esc_attr($name) . '" id="assoc-' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($required ? ' required' : '') . '>';
        echo '</td></tr>';
    }

    /**
     * @return array<int, string>
     */
    private static function months(): array
    {
        return [
            1 => __('January', 'foreningsplugin'),
            2 => __('February', 'foreningsplugin'),
            3 => __('March', 'foreningsplugin'),
            4 => __('April', 'foreningsplugin'),
            5 => __('May', 'foreningsplugin'),
            6 => __('June', 'foreningsplugin'),
            7 => __('July', 'foreningsplugin'),
            8 => __('August', 'foreningsplugin'),
            9 => __('September', 'foreningsplugin'),
            10 => __('October', 'foreningsplugin'),
            11 => __('November', 'foreningsplugin'),
            12 => __('December', 'foreningsplugin'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function roleLabels(): array
    {
        return [
            RoleBundles::SECRETARY => __('Secretary', 'foreningsplugin'),
            RoleBundles::CHAIR => __('Chair', 'foreningsplugin'),
            RoleBundles::TREASURER => __('Treasurer', 'foreningsplugin'),
            RoleBundles::BOARD_MEMBER => __('Board member', 'foreningsplugin'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function postedRoles(string $key): array
    {
        $roles = [];

        if (isset($_POST[$key]) && is_array($_POST[$key])) {
            foreach ($_POST[$key] as $role) {
                $roles[] = sanitize_key((string) $role);
            }
        }

        return $roles;
    }

    private static function postedProfile(): AssociationProfile
    {
        return new AssociationProfile(
            sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? ''))),
            sanitize_text_field(wp_unslash((string) ($_POST['organization_number'] ?? ''))),
            sanitize_textarea_field(wp_unslash((string) ($_POST['address'] ?? ''))),
            sanitize_email(wp_unslash((string) ($_POST['email'] ?? ''))),
            sanitize_text_field(wp_unslash((string) ($_POST['phone'] ?? ''))),
            sanitize_key((string) ($_POST['language'] ?? '')),
            null,
            isset($_POST['membership_year_month']) ? (int) $_POST['membership_year_month'] : 0,
            isset($_POST['membership_year_day']) ? (int) $_POST['membership_year_day'] : 0
        );
    }

    private static function wizard(): SetupWizard
    {
        return new SetupWizard(
            WordpressSetupState::instance(),
            new class implements Authorizer {
                public function allows(string $capability): bool
                {
                    return current_user_can($capability);
                }
            }
        );
    }

    private static function guard(string $nonce): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            self::denied();
        }

        check_admin_referer($nonce);
    }

    private static function denied(): void
    {
        wp_die(esc_html__('You do not have permission to run association setup.', 'foreningsplugin'), '', ['response' => 403]);
    }

    private static function redirect(string $step, string $notice = ''): void
    {
        $args = [
            'page' => self::PAGE,
            'step' => SetupStep::normalize($step),
        ];

        if ($notice !== '') {
            $args['assoc_notice'] = $notice;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';
        $messages = [
            'association_invalid' => __('The association profile could not be saved. Check the fields and enter a name.', 'foreningsplugin'),
            'role_added' => __('The board role was added.', 'foreningsplugin'),
            'role_failed' => __('The board role could not be saved.', 'foreningsplugin'),
            'type_added' => __('The meeting type was added.', 'foreningsplugin'),
            'type_failed' => __('The meeting type could not be saved.', 'foreningsplugin'),
            'minutes_invalid' => __('The minutes permissions could not be saved.', 'foreningsplugin'),
            'privacy_invalid' => __('Retention must be between 1 and 100 years.', 'foreningsplugin'),
            'finish_name_required' => __('Enter the association name before finishing setup.', 'foreningsplugin'),
        ];

        if (! isset($messages[$notice])) {
            return;
        }

        $error = str_contains($notice, 'invalid') || str_contains($notice, 'failed') || str_contains($notice, 'required');
        echo '<div class="notice notice-' . ($error ? 'error' : 'success') . '"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }

    private static function text(string $key): string
    {
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
    }

}
