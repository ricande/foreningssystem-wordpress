<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Board\BoardSeat;
use Foreningssystem\Application\Settings\BuiltinStructure;
use Foreningssystem\Domain\Board\BoardRole;

final class BoardScreen
{
    /**
     * @param list<BoardSeat> $seats
     * @param list<BoardRole> $roles
     * @param list<array{person_id: int, name: string, coverage: string, coverage_on: ?string}> $people
     */
    public static function render(array $seats, array $roles, array $people, bool $canEdit, string $notice): void
    {
        $current = self::where($seats, 'current');
        $upcoming = self::where($seats, 'upcoming');
        $history = self::where($seats, 'history');

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Board', 'foreningsplugin') . '</h1>';
        echo '<p>' . esc_html__('An assignment requires a membership that covers the whole period. The auditor and the election committee follow the same rule. A closed row is not deleted.', 'foreningsplugin') . '</p>';

        if ($notice !== '') {
            echo $notice;
        }

        echo '<div id="assoc-board-current">';
        echo '<h2>' . esc_html__('Current board', 'foreningsplugin') . '</h2>';

        if ($current === []) {
            echo '<p>' . esc_html__('No current board assignments.', 'foreningsplugin') . '</p>';

            if ($seats === []) {
                echo '<p>' . esc_html__('Add the first board assignment.', 'foreningsplugin') . '</p>';
            }
        } else {
            self::table($current, $canEdit, 'current');
        }

        echo '</div>';

        if ($upcoming !== []) {
            echo '<div id="assoc-board-upcoming">';
            echo '<h2>' . esc_html__('Upcoming changes', 'foreningsplugin') . '</h2>';
            self::table($upcoming, $canEdit, 'upcoming');
            echo '</div>';
        }

        echo '<div id="assoc-board-history">';
        echo '<h2>' . esc_html__('History', 'foreningsplugin') . '</h2>';

        if ($history === []) {
            echo '<p>' . esc_html__('No earlier board assignments.', 'foreningsplugin') . '</p>';
        } else {
            foreach ($roles as $role) {
                $rows = [];

                foreach ($history as $seat) {
                    if ($role->id() !== null && $seat->roleId() === $role->id()) {
                        $rows[] = $seat;
                    }
                }

                if ($rows === []) {
                    continue;
                }

                usort($rows, static fn (BoardSeat $left, BoardSeat $right): int => strcmp($right->startedOn(), $left->startedOn()));
                echo '<h3>' . esc_html(self::roleLabel($role->slug(), $role->name())) . '</h3>';
                self::table($rows, false, 'history');
            }
        }

        echo '</div>';

        if ($canEdit) {
            echo '<div id="assoc-board-change">';
            echo '<h2>' . esc_html__('Change board', 'foreningsplugin') . '</h2>';

            foreach ($roles as $role) {
                if ($role->id() === null) {
                    continue;
                }

                if ($role->allowsMultiple()) {
                    echo '<h3>' . esc_html(sprintf(
                        /* translators: %s is the board role name. */
                        __('Add holder: %s', 'foreningsplugin'),
                        self::roleLabel($role->slug(), $role->name())
                    )) . '</h3>';
                    echo '<p>' . esc_html__('Existing holders of this role stay in place.', 'foreningsplugin') . '</p>';
                    self::placeForm($people, $roles, $role->id(), null, __('Add holder', 'foreningsplugin'));
                    continue;
                }

                echo '<div id="assoc-role-' . esc_attr((string) $role->id()) . '">';
                $holders = [];
                $holderEnd = null;

                foreach ($current as $seat) {
                    if ($seat->roleId() !== $role->id()) {
                        continue;
                    }

                    $holders[] = $seat->personName();
                    $holderEnd = $seat->endedOn();
                }

                $scheduled = [];

                foreach ($upcoming as $seat) {
                    if ($seat->roleId() === $role->id()) {
                        $scheduled[] = $seat;
                    }
                }

                $label = self::roleLabel($role->slug(), $role->name());

                if ($scheduled !== []) {
                    echo '<h3>' . esc_html(sprintf(
                        /* translators: %s is the board role name. */
                        __('A future %s is already scheduled:', 'foreningsplugin'),
                        $label
                    )) . '</h3>';

                    foreach ($scheduled as $seat) {
                        echo '<p>' . esc_html($seat->personName() . ' — ' . sprintf(
                            /* translators: %s is a date. */
                            __('Starts %s', 'foreningsplugin'),
                            $seat->startedOn()
                        )) . '</p>';
                        self::cancelForm($seat, $label);
                    }

                    echo '</div>';
                    continue;
                }

                if ($holders === []) {
                    echo '</div>';
                    continue;
                }

                echo '<h3>' . esc_html(sprintf(
                    /* translators: %s is the board role name. */
                    __('Replace %s', 'foreningsplugin'),
                    $label
                )) . '</h3>';
                echo '<p class="description assoc-replace-preview" data-current="' . esc_attr(implode(', ', $holders)) . '" data-end="' . esc_attr((string) $holderEnd) . '">' . esc_html(sprintf(
                    /* translators: %s is the current holder's name. */
                    __('%s is the current holder. If the new start date falls inside this assignment, it ends the day before. A later start leaves the current end date unchanged. The earlier assignment remains in history.', 'foreningsplugin'),
                    implode(', ', $holders)
                )) . '</p>';
                self::placeForm($people, $roles, $role->id(), implode(', ', $holders), sprintf(
                    /* translators: %s is the board role name. */
                    __('Replace %s', 'foreningsplugin'),
                    $label
                ));
                echo '</div>';
            }

            echo '<h3>' . esc_html__('New assignment', 'foreningsplugin') . '</h3>';
            echo '<p class="description">' . esc_html__('For a role with one holder, cancel a scheduled successor before adding another assignment.', 'foreningsplugin') . '</p>';
            self::placeForm($people, $roles, null, null, __('Add assignment', 'foreningsplugin'));
            echo '</div>';
            self::script();
        }

        echo '</div>';
    }

    public static function roleLabel(string $slug, string $stored): string
    {
        $source = BuiltinStructure::boardSource($slug);

        return $source === null ? $stored : __($source, 'foreningsplugin');
    }

    /**
     * @param list<BoardSeat> $seats
     * @return list<BoardSeat>
     */
    private static function where(array $seats, string $state): array
    {
        return array_values(array_filter($seats, static fn (BoardSeat $seat): bool => $seat->state() === $state));
    }

    /**
     * @param list<BoardSeat> $seats
     */
    private static function table(array $seats, bool $canEdit, string $context): void
    {
        echo '<table class="widefat striped"><thead><tr>';

        foreach ([__('Role', 'foreningsplugin'), __('Person', 'foreningsplugin'), __('Dates', 'foreningsplugin'), __('Term', 'foreningsplugin'), __('Public contact', 'foreningsplugin')] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }

        if ($canEdit) {
            echo '<th scope="col">' . esc_html__('Actions', 'foreningsplugin') . '</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ($seats as $seat) {
            $role = self::roleLabel($seat->roleSlug(), $seat->roleName());
            echo '<tr>';
            echo '<td>' . esc_html($role) . '</td>';
            echo '<td><a href="' . esc_url(admin_url('admin.php?page=foreningsplugin-members&assoc_person=' . $seat->personId())) . '">' . esc_html($seat->personName()) . '</a></td>';
            echo '<td>' . esc_html(self::dates($seat, $context)) . '</td>';
            echo '<td>' . esc_html($seat->termLabel()) . '</td>';
            echo '<td>' . esc_html($seat->publicContact()) . '</td>';

            if ($canEdit) {
                echo '<td>';

                if ($context === 'upcoming') {
                    self::cancelForm($seat, $role);
                } elseif ($context === 'current' && $seat->endedOn() === null) {
                    self::endForm($seat, $role);
                }

                echo '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function dates(BoardSeat $seat, string $context): string
    {
        unset($context);

        return match ($seat->datePresentation()) {
            'starts' => sprintf(
                /* translators: %s is the start date. */
                __('Starts %s', 'foreningsplugin'),
                $seat->startedOn()
            ),
            'since' => sprintf(
                /* translators: %s is a date. */
                __('Since %s', 'foreningsplugin'),
                $seat->startedOn()
            ),
            'present' => sprintf(
                /* translators: %s is the start date. */
                __('%s – present', 'foreningsplugin'),
                $seat->startedOn()
            ),
            default => $seat->startedOn() . ' – ' . $seat->endedOn(),
        };
    }

    /**
     * @param list<array{person_id: int, name: string, coverage: string, coverage_on: ?string}> $people
     * @param list<BoardRole> $roles
     */
    private static function placeForm(array $people, array $roles, ?int $roleId, ?string $currentHolder, string $button): void
    {
        $suffix = $roleId === null ? 'new' : (string) $roleId . ($currentHolder === null ? '-add' : '-replace');
        echo '<form method="post" class="' . esc_attr($currentHolder === null ? 'assoc-place-form' : 'assoc-replace-form') . '" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_place_assignment">';

        if ($currentHolder !== null) {
            echo '<input type="hidden" class="assoc-current-holder" value="' . esc_attr($currentHolder) . '">';
        }

        wp_nonce_field('assoc_place_assignment');
        self::personSelect($people);

        if ($roleId === null) {
            echo '<p><label for="assoc-new-role">' . esc_html__('Role', 'foreningsplugin') . '</label><br>';
            echo '<select id="assoc-new-role" name="role_id" required>';
            echo '<option value="">' . esc_html__('Choose role', 'foreningsplugin') . '</option>';

            foreach ($roles as $role) {
                if ($role->id() === null) {
                    continue;
                }

                echo '<option value="' . esc_attr((string) $role->id()) . '">' . esc_html(self::roleLabel($role->slug(), $role->name())) . '</option>';
            }

            echo '</select></p>';
            self::input('assoc-new-start', 'started_on', __('Start date', 'foreningsplugin'), 'date', true);
            self::input('assoc-new-end', 'ended_on', __('End date', 'foreningsplugin'), 'date', false);
            echo '<p class="description">' . esc_html__('Leave the end date empty if the assignment continues until it is changed.', 'foreningsplugin') . '</p>';
        } else {
            echo '<input type="hidden" name="role_id" value="' . esc_attr((string) $roleId) . '">';
            self::input('assoc-start-' . $suffix, 'started_on', __('Start date', 'foreningsplugin'), 'date', true);
        }

        self::input('assoc-contact-' . $suffix, 'public_contact', __('Public contact', 'foreningsplugin'), 'text', false);
        echo '<p class="description">' . esc_html__('This may be shown on the public website for this board role.', 'foreningsplugin') . '</p>';
        self::input('assoc-term-' . $suffix, 'term_label', __('Term', 'foreningsplugin'), 'text', false);
        submit_button($button, 'primary', 'submit-' . $suffix);
        echo '</form>';
    }

    /**
     * @param list<array{person_id: int, name: string, coverage: string, coverage_on: ?string}> $people
     */
    private static function personSelect(array $people): void
    {
        echo '<p><label>' . esc_html__('Person', 'foreningsplugin') . '<br><select name="person_id" required>';
        echo '<option value="">' . esc_html__('Choose person', 'foreningsplugin') . '</option>';

        foreach ($people as $person) {
            $context = match ($person['coverage']) {
                'active' => __('active member', 'foreningsplugin'),
                'ends' => sprintf(
                    /* translators: %s is a date. */
                    __('membership ends %s', 'foreningsplugin'),
                    (string) $person['coverage_on']
                ),
                'starts' => sprintf(
                    /* translators: %s is a date. */
                    __('membership starts %s', 'foreningsplugin'),
                    (string) $person['coverage_on']
                ),
                default => __('not a current member', 'foreningsplugin'),
            };
            echo '<option value="' . esc_attr((string) $person['person_id']) . '" data-name="' . esc_attr($person['name']) . '">';
            echo esc_html($person['name'] . ' — ' . $context);
            echo '</option>';
        }

        echo '</select></label></p>';
    }

    private static function cancelForm(BoardSeat $seat, string $role): void
    {
        echo '<form method="post" class="assoc-cancel-form" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_cancel_assignment">';
        echo '<input type="hidden" name="assignment_id" value="' . esc_attr((string) $seat->assignmentId()) . '">';
        wp_nonce_field('assoc_cancel_assignment');
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: 1: person name, 2: role name, 3: start date. */
            __('Cancel %1$s\'s scheduled %2$s assignment starting %3$s? This assignment has not started and will not be kept as board history.', 'foreningsplugin'),
            $seat->personName(),
            $role,
            $seat->startedOn()
        )) . '</p>';
        echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__('Cancel this scheduled assignment', 'foreningsplugin') . '</label></p>';
        submit_button(__('Cancel scheduled assignment', 'foreningsplugin'), 'secondary', 'cancel-' . $seat->assignmentId());
        echo '</form>';
    }

    private static function endForm(BoardSeat $seat, string $role): void
    {
        echo '<form method="post" class="assoc-end-form" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_end_assignment">';
        echo '<input type="hidden" name="assignment_id" value="' . esc_attr((string) $seat->assignmentId()) . '">';
        wp_nonce_field('assoc_end_assignment');
        echo '<p class="description assoc-end-preview" data-person="' . esc_attr($seat->personName()) . '" data-role="' . esc_attr($role) . '">' . esc_html(sprintf(
            /* translators: 1: person name, 2: role name. */
            __('End %1$s\'s %2$s assignment on the date below? The historical assignment will remain.', 'foreningsplugin'),
            $seat->personName(),
            $role
        )) . '</p>';
        echo '<p><label>' . esc_html__('End date', 'foreningsplugin') . '<br>';
        echo '<input type="date" name="ended_on" required></label></p>';
        echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__('End this assignment', 'foreningsplugin') . '</label></p>';
        submit_button(__('End assignment', 'foreningsplugin'), 'secondary', 'end-' . $seat->assignmentId());
        echo '</form>';
    }

    private static function input(string $id, string $name, string $label, string $type, bool $required): void
    {
        echo '<p><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><br>';
        echo '<input class="regular-text" id="' . esc_attr($id) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '"' . ($required ? ' required' : '') . '></p>';
    }

    private static function script(): void
    {
        $replace = __('%1$s\'s assignment will end %2$s. %3$s\'s assignment will begin %4$s. The previous assignment remains in history.', 'foreningsplugin');
        $gap = __('The current assignment still ends %1$s. %2$s\'s assignment will begin %3$s. The earlier assignment remains in history.', 'foreningsplugin');
        $end = __('End %1$s\'s %2$s assignment on %3$s? The historical assignment will remain.', 'foreningsplugin');
        echo '<script>';
        echo 'document.addEventListener("DOMContentLoaded",function(){';
        echo 'var replaceTemplate=' . wp_json_encode($replace) . ';';
        echo 'var gapTemplate=' . wp_json_encode($gap) . ';';
        echo 'var endTemplate=' . wp_json_encode($end) . ';';
        echo 'function fill(template,values){return template.replace(/%(\\d+)\\$s/g,function(_,index){return values[Number(index)-1]||"";});}';
        echo 'function previousDay(iso){var parts=iso.split("-");if(parts.length!==3){return "";}var date=new Date(Date.UTC(Number(parts[0]),Number(parts[1])-1,Number(parts[2])));if(Number.isNaN(date.getTime())){return "";}date.setUTCDate(date.getUTCDate()-1);var month=String(date.getUTCMonth()+1).padStart(2,"0");var day=String(date.getUTCDate()).padStart(2,"0");return date.getUTCFullYear()+"-"+month+"-"+day;}';
        echo 'document.querySelectorAll(".assoc-replace-form").forEach(function(form){var preview=form.previousElementSibling;var person=form.querySelector("[name=person_id]");var start=form.querySelector("[name=started_on]");var holder=form.querySelector(".assoc-current-holder");if(!preview||!person||!start||!holder){return;}var refresh=function(){var selected=person.options[person.selectedIndex];var name=selected?selected.getAttribute("data-name")||"":"";var end=previousDay(start.value);if(!name||!end){return;}var planned=preview.getAttribute("data-end")||"";if(planned&&start.value>planned){preview.textContent=fill(gapTemplate,[planned,name,start.value]);return;}preview.textContent=fill(replaceTemplate,[holder.value,end,name,start.value]);};person.addEventListener("change",refresh);start.addEventListener("change",refresh);start.addEventListener("input",refresh);});';
        echo 'document.querySelectorAll(".assoc-end-form").forEach(function(form){var preview=form.querySelector(".assoc-end-preview");var date=form.querySelector("[name=ended_on]");if(!preview||!date){return;}date.addEventListener("change",function(){if(!date.value){return;}preview.textContent=fill(endTemplate,[preview.getAttribute("data-person")||"",preview.getAttribute("data-role")||"",date.value]);});});';
        echo '});</script>';
    }
}
