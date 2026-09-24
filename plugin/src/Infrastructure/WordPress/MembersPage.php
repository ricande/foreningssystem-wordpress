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
            WordpressPeople::service()->register(
                self::text('first_name'),
                self::text('last_name'),
                self::text('email'),
                self::text('membership_number'),
                self::text('membership_type') === '' ? 'ordinarie' : self::text('membership_type'),
                AssociationDate::fromIso(self::text('started_on'))
            );
            self::redirect('created');
        } catch (MembershipRuleException $error) {
            self::redirect($error->getMessage() === 'Membership number is already used.' ? 'duplicate_number' : 'overlap');
        } catch (\InvalidArgumentException) {
            self::redirect('invalid');
        }
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
            wp_die(esc_html__('Du har inte behörighet att se medlemmar.', 'foreningsplugin'));
        }

        $canEdit = current_user_can(Capabilities::EDIT_MEMBERS);
        $records = WordpressPeople::service()->listPeople();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Medlemmar', 'foreningsplugin') . '</h1>';
        self::notice();

        if ($canEdit) {
            echo '<h2>' . esc_html__('Ny person', 'foreningsplugin') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="assoc_register_person">';
            wp_nonce_field('assoc_register_person');
            self::field('first_name', __('Förnamn', 'foreningsplugin'), 'text', true);
            self::field('last_name', __('Efternamn', 'foreningsplugin'), 'text', true);
            self::field('email', __('E-post', 'foreningsplugin'), 'email', false);
            self::field('membership_number', __('Medlemsnummer', 'foreningsplugin'), 'text', true);
            self::field('membership_type', __('Medlemstyp', 'foreningsplugin'), 'text', false);
            self::field('started_on', __('Startdatum', 'foreningsplugin'), 'date', true);
            submit_button(__('Spara person', 'foreningsplugin'));
            echo '</form>';
        }

        echo '<h2>' . esc_html__('Personer', 'foreningsplugin') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Namn', 'foreningsplugin'), __('E-post', 'foreningsplugin'), __('Medlemsnummer', 'foreningsplugin'), __('Status', 'foreningsplugin'), __('Period', 'foreningsplugin')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        if ($canEdit) {
            echo '<th>' . esc_html__('Åtgärd', 'foreningsplugin') . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($records === []) {
            echo '<tr><td colspan="6">' . esc_html__('Inga personer ännu.', 'foreningsplugin') . '</td></tr>';
        }

        foreach ($records as $record) {
            $person = $record->person();
            $membership = $record->membership();
            $personId = (int) $person->id();
            echo '<tr>';
            echo '<td>' . esc_html($person->firstName() . ' ' . $person->lastName()) . '</td>';
            echo '<td>' . esc_html($person->email()) . '</td>';
            echo '<td>' . esc_html($membership === null ? '' : $membership->number()) . '</td>';
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
                    submit_button(__('Avsluta medlemskap', 'foreningsplugin'), 'secondary', 'submit', false);
                    echo '</form>';
                }
                if ($person->status() !== PersonStatus::Deceased) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    echo '<input type="hidden" name="action" value="assoc_mark_deceased">';
                    echo '<input type="hidden" name="person_id" value="' . esc_attr((string) $personId) . '">';
                    wp_nonce_field('assoc_mark_deceased');
                    echo '<input type="date" name="deceased_on" required> ';
                    submit_button(__('Markera som avliden', 'foreningsplugin'), 'delete', 'submit', false);
                    echo '</form>';
                }
                echo '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private static function guardEdit(string $nonce): void
    {
        if (! current_user_can(Capabilities::EDIT_MEMBERS)) {
            wp_die(esc_html__('Du har inte behörighet att ändra medlemmar.', 'foreningsplugin'), '', ['response' => 403]);
        }

        check_admin_referer($nonce);
    }

    private static function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => 'foreningsplugin-members',
            'assoc_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    private static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';
        $messages = [
            'created' => __('Personen är sparad.', 'foreningsplugin'),
            'ended' => __('Medlemskapet är avslutat. Personen finns kvar.', 'foreningsplugin'),
            'deceased' => __('Personen är markerad som avliden och öppna medlemskap är avslutade.', 'foreningsplugin'),
            'duplicate_number' => __('Medlemsnumret används redan.', 'foreningsplugin'),
            'overlap' => __('Medlemsperioderna överlappar.', 'foreningsplugin'),
            'already_ended' => __('Medlemskapet är redan avslutat.', 'foreningsplugin'),
            'assignment' => __('Ett öppet styrelseuppdrag passar inte datumet, så inget ändrades.', 'foreningsplugin'),
            'invalid' => __('Kontrollera uppgifterna och försök igen.', 'foreningsplugin'),
        ];

        if (! isset($messages[$notice])) {
            return;
        }

        $class = in_array($notice, ['created', 'ended', 'deceased'], true) ? 'notice-success' : 'notice-error';
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
            return __('Avliden', 'foreningsplugin');
        }

        return match ($membershipStatus) {
            MembershipStatus::Active => __('Aktiv', 'foreningsplugin'),
            MembershipStatus::Pending => __('Väntande', 'foreningsplugin'),
            MembershipStatus::Dormant => __('Vilande', 'foreningsplugin'),
            MembershipStatus::Ended => __('Avslutad', 'foreningsplugin'),
            default => __('Utan medlemskap', 'foreningsplugin'),
        };
    }
}
