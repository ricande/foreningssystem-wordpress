<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\Settings\StructureRuleException;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Board\BoardRole;
use Foreningssystem\Domain\Meeting\MeetingType;
use RuntimeException;

final class AssociationSettingsPage
{
    public const PAGE = 'foreningsplugin-settings';

    public const SECTION_PROFILE = 'profile';

    public const SECTION_BOARD_ROLES = 'board-roles';

    public const SECTION_MEETING_TYPES = 'meeting-types';

    public const SECTION_MINUTES_LOCK = 'minutes-lock';

    public const SECTION_MINUTES_PUBLISH = 'minutes-publish';

    public const SECTION_RETENTION = 'retention';

    /**
     * Old top-level page slugs → settings hub sections.
     *
     * @var array<string, string>
     */
    public const LEGACY_PAGES = [
        'foreningsplugin-profile' => self::SECTION_PROFILE,
        'foreningsplugin-minutes-lock' => self::SECTION_MINUTES_LOCK,
        'foreningsplugin-minutes-publish' => self::SECTION_MINUTES_PUBLISH,
        'foreningsplugin-retention' => self::SECTION_RETENTION,
    ];

    public static function addBoardRole(): void
    {
        self::guard('assoc_add_board_role');
        $name = self::text('board_role_name');
        $allowsMultiple = isset($_POST['allows_multiple']) && (string) $_POST['allows_multiple'] === '1';

        try {
            WordpressAssociationSettings::boardRoles()->create($name, $allowsMultiple);
            self::redirect('role_added');
        } catch (NotAllowed) {
            self::denied();
        } catch (StructureRuleException $error) {
            self::redirect(self::ruleNotice($error, 'role'));
        } catch (RuntimeException) {
            self::redirect('role_failed');
        }
    }

    public static function updateBoardRole(): void
    {
        self::guard('assoc_update_board_role');
        $roleId = self::integer('role_id');
        $name = self::text('role_name');
        $allowsMultiple = isset($_POST['allows_multiple']) && (string) $_POST['allows_multiple'] === '1';

        try {
            WordpressAssociationSettings::boardRoles()->update($roleId, $name, $allowsMultiple);
            self::redirect('role_updated');
        } catch (NotAllowed) {
            self::denied();
        } catch (StructureRuleException $error) {
            self::redirect(self::ruleNotice($error, 'role'));
        } catch (RuntimeException) {
            self::redirect('role_failed');
        }
    }

    public static function moveBoardRole(): void
    {
        self::guard('assoc_move_board_role');

        try {
            WordpressAssociationSettings::boardRoles()->move(self::integer('role_id'), self::direction());
            self::redirect('role_moved');
        } catch (NotAllowed) {
            self::denied();
        } catch (StructureRuleException $error) {
            self::redirect(self::ruleNotice($error, 'role'));
        } catch (RuntimeException) {
            self::redirect('role_failed');
        }
    }

    public static function addMeetingType(): void
    {
        self::guard('assoc_add_meeting_type');

        try {
            WordpressAssociationSettings::meetingTypes()->create(self::text('meeting_type_name'));
            self::redirect('type_added', self::SECTION_MEETING_TYPES);
        } catch (NotAllowed) {
            self::denied();
        } catch (StructureRuleException $error) {
            self::redirect(self::ruleNotice($error, 'type'), self::SECTION_MEETING_TYPES);
        } catch (RuntimeException) {
            self::redirect('type_failed', self::SECTION_MEETING_TYPES);
        }
    }

    public static function renameMeetingType(): void
    {
        self::guard('assoc_rename_meeting_type');

        try {
            WordpressAssociationSettings::meetingTypes()->rename(self::integer('type_id'), self::text('type_name'));
            self::redirect('type_renamed', self::SECTION_MEETING_TYPES);
        } catch (NotAllowed) {
            self::denied();
        } catch (StructureRuleException $error) {
            self::redirect(self::ruleNotice($error, 'type'), self::SECTION_MEETING_TYPES);
        } catch (RuntimeException) {
            self::redirect('type_failed', self::SECTION_MEETING_TYPES);
        }
    }

    public static function moveMeetingType(): void
    {
        self::guard('assoc_move_meeting_type');

        try {
            WordpressAssociationSettings::meetingTypes()->move(self::integer('type_id'), self::direction());
            self::redirect('type_moved', self::SECTION_MEETING_TYPES);
        } catch (NotAllowed) {
            self::denied();
        } catch (StructureRuleException $error) {
            self::redirect(self::ruleNotice($error, 'type'), self::SECTION_MEETING_TYPES);
        } catch (RuntimeException) {
            self::redirect('type_failed', self::SECTION_MEETING_TYPES);
        }
    }

    public static function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            self::denied();
        }

        $section = self::requestedSection();

        match ($section) {
            self::SECTION_PROFILE => AssociationProfilePage::render(),
            self::SECTION_BOARD_ROLES => self::renderBoardRoles(),
            self::SECTION_MEETING_TYPES => self::renderMeetingTypes(),
            self::SECTION_MINUTES_LOCK => MinutesLockPage::render(),
            self::SECTION_MINUTES_PUBLISH => MinutesPublishPage::render(),
            self::SECTION_RETENTION => RetentionPage::render(),
            default => self::renderHub(),
        };
    }

    public static function redirectLegacyPage(): void
    {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';

        if (! isset(self::LEGACY_PAGES[$page])) {
            return;
        }

        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            self::denied();
        }

        $args = [
            'page' => self::PAGE,
            'section' => self::LEGACY_PAGES[$page],
        ];

        foreach (['assoc_notice', 'assoc_anonymized', 'assoc_audits', 'edit_role', 'edit_type'] as $key) {
            if (! isset($_GET[$key])) {
                continue;
            }

            $args[$key] = sanitize_text_field(wp_unslash((string) $_GET[$key]));
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * @return list<string>
     */
    public static function knownSections(): array
    {
        return [
            self::SECTION_PROFILE,
            self::SECTION_BOARD_ROLES,
            self::SECTION_MEETING_TYPES,
            self::SECTION_MINUTES_LOCK,
            self::SECTION_MINUTES_PUBLISH,
            self::SECTION_RETENTION,
        ];
    }

    public static function settingsUrl(string $section = '', array $args = []): string
    {
        $query = array_merge(['page' => self::PAGE], $args);

        if ($section !== '') {
            $query['section'] = $section;
        }

        return add_query_arg($query, admin_url('admin.php'));
    }

    public static function backToHub(): void
    {
        echo '<p><a href="' . esc_url(self::settingsUrl()) . '">' . esc_html__('Back to settings', 'foreningsplugin') . '</a></p>';
    }

    private static function renderHub(): void
    {
        echo '<div class="wrap assoc-settings">';
        echo '<h1>' . esc_html__('Association settings', 'foreningsplugin') . '</h1>';
        echo '<p>' . esc_html__('Choose what to configure. Each screen focuses on one part of the association structure and permissions.', 'foreningsplugin') . '</p>';

        $entries = [
            [
                'section' => self::SECTION_PROFILE,
                'title' => __('Association profile', 'foreningsplugin'),
                'text' => __('The association\'s name, contact details, language, logo, and when the membership year starts.', 'foreningsplugin'),
            ],
            [
                'section' => self::SECTION_BOARD_ROLES,
                'title' => __('Board roles', 'foreningsplugin'),
                'text' => __('Board roles describe the structure. You do not assign people here. Built-in roles keep their meaning. You may add a custom role.', 'foreningsplugin'),
            ],
            [
                'section' => self::SECTION_MEETING_TYPES,
                'title' => __('Meeting types', 'foreningsplugin'),
                'text' => __('Meeting types describe how the association meets. You do not create meetings here. Built-in types keep their meaning. You may add a custom type.', 'foreningsplugin'),
            ],
            [
                'section' => self::SECTION_MINUTES_LOCK,
                'title' => __('Lock minutes', 'foreningsplugin'),
                'text' => __('Choose which association roles may finalize and lock minutes.', 'foreningsplugin'),
            ],
            [
                'section' => self::SECTION_MINUTES_PUBLISH,
                'title' => __('Publish minutes', 'foreningsplugin'),
                'text' => __('Choose which association roles may publish locked minutes on the site.', 'foreningsplugin'),
            ],
            [
                'section' => self::SECTION_RETENTION,
                'title' => __('Retention', 'foreningsplugin'),
                'text' => __('How long contact details and audit events are kept after a membership has ended.', 'foreningsplugin'),
            ],
        ];

        echo '<ul class="assoc-settings-hub">';

        foreach ($entries as $entry) {
            self::hubCard(self::settingsUrl($entry['section']), $entry['title'], $entry['text']);
        }

        self::hubCard(
            self::pageUrl(SetupPage::PAGE),
            __('Run setup guide again', 'foreningsplugin'),
            __('Open the same guided setup again. Reopening it does not mark setup incomplete or reset association data.', 'foreningsplugin')
        );
        echo '</ul></div>';
    }

    private static function renderBoardRoles(): void
    {
        $roles = WordpressAssociationSettings::boardRoles();
        $editRole = isset($_GET['edit_role']) ? absint($_GET['edit_role']) : 0;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Board roles', 'foreningsplugin') . '</h1>';
        self::backToHub();
        echo '<p>' . esc_html__('Board roles describe the structure. You do not assign people here. Built-in roles keep their meaning. You may add a custom role.', 'foreningsplugin') . '</p>';
        self::notice();

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Role', 'foreningsplugin') . '</th>';
        echo '<th>' . esc_html__('Holders', 'foreningsplugin') . '</th>';
        echo '<th>' . esc_html__('Order', 'foreningsplugin') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($roles->catalog() as $role) {
            $id = (int) $role->id();
            $label = BoardScreen::roleLabel($role->slug(), $role->name());
            $builtIn = $roles->builtIn($role);
            $used = $roles->used($id);
            echo '<tr>';
            echo '<td>' . esc_html($label);
            if (! $builtIn && $used) {
                echo '<p class="description">' . esc_html__('This role has been used in a board assignment. Its name, and whether one or several people may hold it, stay unchanged so earlier assignments remain clear. You can still change the display order.', 'foreningsplugin') . '</p>';
            }
            echo '</td>';
            echo '<td>' . esc_html($role->allowsMultiple() ? __('Multiple holders', 'foreningsplugin') : __('One holder', 'foreningsplugin')) . '</td>';
            echo '<td>';
            if (! $builtIn && ! $used) {
                echo '<a class="button" href="' . esc_url(self::settingsUrl(self::SECTION_BOARD_ROLES, ['edit_role' => (string) $id])) . '">' . esc_html(sprintf(
                    /* translators: %s: board role name */
                    __('Edit %s', 'foreningsplugin'),
                    $label
                )) . '</a> ';
            }
            self::moveButtons('assoc_move_board_role', 'role_id', $id, $label);
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        self::boardEditForm($roles->catalog(), $editRole, $roles);
        self::addBoardRoleForm();
        echo '</div>';
    }

    private static function renderMeetingTypes(): void
    {
        $types = WordpressAssociationSettings::meetingTypes();
        $editType = isset($_GET['edit_type']) ? absint($_GET['edit_type']) : 0;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Meeting types', 'foreningsplugin') . '</h1>';
        self::backToHub();
        echo '<p>' . esc_html__('Meeting types describe how the association meets. You do not create meetings here. Built-in types keep their meaning. You may add a custom type.', 'foreningsplugin') . '</p>';
        self::notice();

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Meeting type', 'foreningsplugin') . '</th>';
        echo '<th>' . esc_html__('Order', 'foreningsplugin') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($types->catalog() as $type) {
            $id = (int) $type->id();
            $label = MeetingLabels::type($type);
            $builtIn = $types->builtIn($type);
            $used = $types->used($id);
            echo '<tr><td>' . esc_html($label);
            if (! $builtIn && $used) {
                echo '<p class="description">' . esc_html__('This meeting type has been used by a meeting or template. Its name is now preserved for history. You can still change its display order.', 'foreningsplugin') . '</p>';
            }
            echo '</td><td>';
            if (! $builtIn && ! $used) {
                echo '<a class="button" href="' . esc_url(self::settingsUrl(self::SECTION_MEETING_TYPES, ['edit_type' => (string) $id])) . '">' . esc_html(sprintf(
                    /* translators: %s: meeting type name */
                    __('Edit %s', 'foreningsplugin'),
                    $label
                )) . '</a> ';
            }
            self::moveButtons('assoc_move_meeting_type', 'type_id', $id, $label);
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        self::meetingEditForm($types->catalog(), $editType, $types);
        self::addMeetingTypeForm();
        echo '</div>';
    }

    /**
     * @param list<BoardRole> $catalog
     */
    private static function boardEditForm(array $catalog, int $editRole, \Foreningssystem\Application\Settings\BoardRoleDefinitions $roles): void
    {
        if ($editRole < 1) {
            return;
        }

        $role = null;

        foreach ($catalog as $candidate) {
            if ($candidate->id() === $editRole) {
                $role = $candidate;
            }
        }

        if (! $role instanceof BoardRole || $roles->builtIn($role) || $roles->used($editRole)) {
            return;
        }

        $label = BoardScreen::roleLabel($role->slug(), $role->name());
        echo '<h3>' . esc_html(sprintf(
            /* translators: %s: board role name */
            __('Edit %s', 'foreningsplugin'),
            $label
        )) . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_update_board_role">';
        echo '<input type="hidden" name="role_id" value="' . esc_attr((string) $editRole) . '">';
        wp_nonce_field('assoc_update_board_role');
        echo '<p><label for="assoc-role-name">' . esc_html__('Name', 'foreningsplugin') . '</label><br>';
        echo '<input id="assoc-role-name" name="role_name" type="text" required maxlength="100" value="' . esc_attr($role->name()) . '"></p>';
        echo '<p><label><input type="checkbox" name="allows_multiple" value="1"' . ($role->allowsMultiple() ? ' checked' : '') . '> ';
        echo esc_html__('Allow several people to hold this role at the same time', 'foreningsplugin') . '</label></p>';
        echo '<p><button type="submit">' . esc_html__('Save role', 'foreningsplugin') . '</button></p>';
        echo '</form>';
    }

    /**
     * @param list<MeetingType> $catalog
     */
    private static function meetingEditForm(array $catalog, int $editType, \Foreningssystem\Application\Settings\MeetingTypeDefinitions $types): void
    {
        if ($editType < 1) {
            return;
        }

        $type = null;

        foreach ($catalog as $candidate) {
            if ($candidate->id() === $editType) {
                $type = $candidate;
            }
        }

        if (! $type instanceof MeetingType || $types->builtIn($type) || $types->used($editType)) {
            return;
        }

        echo '<h3>' . esc_html(sprintf(
            /* translators: %s: meeting type name */
            __('Edit %s', 'foreningsplugin'),
            MeetingLabels::type($type)
        )) . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_rename_meeting_type">';
        echo '<input type="hidden" name="type_id" value="' . esc_attr((string) $editType) . '">';
        wp_nonce_field('assoc_rename_meeting_type');
        echo '<p><label for="assoc-type-name">' . esc_html__('Name', 'foreningsplugin') . '</label><br>';
        echo '<input id="assoc-type-name" name="type_name" type="text" required maxlength="100" value="' . esc_attr($type->name()) . '"></p>';
        echo '<p><button type="submit">' . esc_html__('Save meeting type', 'foreningsplugin') . '</button></p>';
        echo '</form>';
    }

    private static function addBoardRoleForm(): void
    {
        echo '<h3>' . esc_html__('Add board role', 'foreningsplugin') . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_add_board_role">';
        wp_nonce_field('assoc_add_board_role');
        echo '<p><label for="assoc-new-role">' . esc_html__('Name', 'foreningsplugin') . '</label><br>';
        echo '<input id="assoc-new-role" name="board_role_name" type="text" required maxlength="100"></p>';
        echo '<p><label><input type="checkbox" name="allows_multiple" value="1"> ';
        echo esc_html__('Allow several people to hold this role at the same time', 'foreningsplugin') . '</label></p>';
        echo '<p><button type="submit">' . esc_html__('Add role', 'foreningsplugin') . '</button></p>';
        echo '</form>';
    }

    private static function addMeetingTypeForm(): void
    {
        echo '<h3>' . esc_html__('Add meeting type', 'foreningsplugin') . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="assoc_add_meeting_type">';
        wp_nonce_field('assoc_add_meeting_type');
        echo '<p><label for="assoc-new-type">' . esc_html__('Name', 'foreningsplugin') . '</label><br>';
        echo '<input id="assoc-new-type" name="meeting_type_name" type="text" required maxlength="100"></p>';
        echo '<p><button type="submit">' . esc_html__('Add meeting type', 'foreningsplugin') . '</button></p>';
        echo '</form>';
    }

    private static function moveButtons(string $action, string $idField, int $id, string $label): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="' . esc_attr($idField) . '" value="' . esc_attr((string) $id) . '">';
        echo '<input type="hidden" name="direction" value="up">';
        wp_nonce_field($action);
        echo '<button type="submit">' . esc_html(sprintf(
            /* translators: %s: board role or meeting type name */
            __('Move %s up', 'foreningsplugin'),
            $label
        )) . '</button></form> ';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="' . esc_attr($idField) . '" value="' . esc_attr((string) $id) . '">';
        echo '<input type="hidden" name="direction" value="down">';
        wp_nonce_field($action);
        echo '<button type="submit">' . esc_html(sprintf(
            /* translators: %s: board role or meeting type name */
            __('Move %s down', 'foreningsplugin'),
            $label
        )) . '</button></form>';
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
        wp_die(esc_html__('You do not have permission to change association settings.', 'foreningsplugin'), '', ['response' => 403]);
    }

    private static function redirect(string $notice, string $section = self::SECTION_BOARD_ROLES): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => self::PAGE,
            'section' => $section,
            'assoc_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    private static function ruleNotice(StructureRuleException $error, string $prefix): string
    {
        return match ($error->rule()) {
            StructureRuleException::DUPLICATE => $prefix . '_duplicate',
            StructureRuleException::BUILTIN => $prefix . '_builtin',
            StructureRuleException::USED => $prefix . '_used',
            default => $prefix . '_invalid',
        };
    }

    private static function notice(): void
    {
        $notice = isset($_GET['assoc_notice']) ? sanitize_key((string) $_GET['assoc_notice']) : '';
        $messages = [
            'role_added' => __('The board role was added.', 'foreningsplugin'),
            'role_updated' => __('The board role was saved.', 'foreningsplugin'),
            'role_moved' => __('The board role order was saved.', 'foreningsplugin'),
            'role_duplicate' => __('A board role with that name already exists.', 'foreningsplugin'),
            'role_builtin' => __('A built-in board role keeps its name and whether one or several people may hold it.', 'foreningsplugin'),
            'role_used' => __('This role has been used in a board assignment. Its name, and whether one or several people may hold it, stay unchanged so earlier assignments remain clear. You can still change the display order.', 'foreningsplugin'),
            'role_invalid' => __('Enter a board role name.', 'foreningsplugin'),
            'role_failed' => __('The board role could not be saved.', 'foreningsplugin'),
            'type_added' => __('The meeting type was added.', 'foreningsplugin'),
            'type_renamed' => __('The meeting type was saved.', 'foreningsplugin'),
            'type_moved' => __('The meeting type order was saved.', 'foreningsplugin'),
            'type_duplicate' => __('A meeting type with that name already exists.', 'foreningsplugin'),
            'type_builtin' => __('A built-in meeting type keeps its name.', 'foreningsplugin'),
            'type_used' => __('This meeting type has been used by a meeting or template. Its name is now preserved for history. You can still change its display order.', 'foreningsplugin'),
            'type_invalid' => __('Enter a meeting type name.', 'foreningsplugin'),
            'type_failed' => __('The meeting type could not be saved.', 'foreningsplugin'),
        ];

        if (! isset($messages[$notice])) {
            return;
        }

        $error = str_contains($notice, 'duplicate') || str_contains($notice, 'builtin') || str_contains($notice, 'used') || str_contains($notice, 'invalid') || str_contains($notice, 'failed');
        echo '<div class="notice notice-' . ($error ? 'error' : 'success') . '"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }

    private static function text(string $key): string
    {
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
    }

    private static function integer(string $key): int
    {
        return isset($_POST[$key]) ? absint($_POST[$key]) : 0;
    }

    private static function direction(): string
    {
        return isset($_POST['direction']) ? sanitize_key((string) $_POST['direction']) : '';
    }

    private static function requestedSection(): string
    {
        $section = isset($_GET['section']) ? sanitize_key((string) $_GET['section']) : '';

        return in_array($section, self::knownSections(), true) ? $section : '';
    }

    private static function hubCard(string $url, string $title, string $text): void
    {
        echo '<li class="assoc-settings-card">';
        echo '<a class="assoc-settings-card-link" href="' . esc_url($url) . '">';
        echo '<h2 class="assoc-settings-card-title">' . esc_html($title) . '</h2>';
        echo '<p class="assoc-settings-card-text">' . esc_html($text) . '</p>';
        echo '</a></li>';
    }

    /**
     * @param array<string, string> $args
     */
    private static function pageUrl(string $page, array $args = []): string
    {
        return add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php'));
    }
}
