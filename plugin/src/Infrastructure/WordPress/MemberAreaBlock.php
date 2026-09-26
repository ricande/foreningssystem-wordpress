<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\MemberArea\MemberAreaSnapshot;
use Foreningssystem\Application\MemberArea\MemberAreaState;
use Foreningssystem\Application\MemberArea\MemberAreaTiming;
use Foreningssystem\Domain\Membership\MembershipKind;

final class MemberAreaBlock
{
    public static function register(): void
    {
        register_block_type('foreningsplugin/member-area', [
            'api_version' => 3,
            'title' => __('Member area', 'foreningsplugin'),
            'description' => __('Shows the logged-in person\'s own membership information.', 'foreningsplugin'),
            'category' => 'widgets',
            'icon' => 'id',
            'textdomain' => 'foreningsplugin',
            'supports' => [
                'html' => false,
            ],
            'render_callback' => [self::class, 'render'],
        ]);
    }

    /**
     * Block attributes cannot choose a person. The logged-in WordPress user is the viewer.
     *
     * @param array<string, mixed> $attributes
     */
    public static function viewer(int $wordpressUserId, array $attributes): int
    {
        unset($attributes);

        return $wordpressUserId;
    }

    /**
     * The current WordPress account is the only identity. Block attributes and request
     * parameters cannot select another person.
     *
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = [], string $content = ''): string
    {
        unset($attributes, $content);
        PersonalizedOutput::doNotCache();
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $snapshot = WordpressMemberArea::open($userId);
        $documents = null;

        if ($snapshot->state === MemberAreaState::Linked && $snapshot->activeMember) {
            $listed = WordpressDocuments::archive()->memberList();
            $documents = [];

            foreach ($listed ?? [] as $document) {
                $documents[] = [
                    'title' => $document->title(),
                    'url' => PublicDocumentsBlock::downloadUrl($document),
                ];
            }

            if ($listed === null) {
                $documents = null;
            }
        }

        $return = self::currentUrl();

        return self::present(
            $snapshot,
            $documents,
            function_exists('wp_login_url') ? wp_login_url($return) : $return,
            function_exists('wp_logout_url') ? wp_logout_url($return) : $return
        );
    }

    /**
     * @param list<array{title: string, url: string}>|null $documents
     */
    public static function present(MemberAreaSnapshot $snapshot, ?array $documents, string $loginUrl, string $logoutUrl): string
    {
        if ($snapshot->state === MemberAreaState::LoggedOut) {
            return '<section class="foreningsplugin-member-area"><p>'
                . self::phrase('Log in to view your membership.')
                . '</p><p><a href="' . self::href($loginUrl) . '">'
                . self::phrase('Log in')
                . '</a></p></section>';
        }

        if ($snapshot->state === MemberAreaState::Unlinked) {
            return '<section class="foreningsplugin-member-area"><p>'
                . self::phrase('This WordPress account is not linked to a member record. Contact the association if you think this is incorrect.')
                . '</p></section>';
        }

        if ($snapshot->state !== MemberAreaState::Linked) {
            return '<section class="foreningsplugin-member-area"><p>'
                . self::phrase('Your member information is not available.')
                . '</p></section>';
        }

        $html = '<div class="foreningsplugin-member-area">';
        $html .= '<h2>' . self::phrase('Member area') . '</h2>';
        $html .= '<p>' . self::text(sprintf(
            /* translators: %s is the member's own name */
            self::translated('Hello %s'),
            $snapshot->name
        )) . '</p>';
        $html .= self::details($snapshot);
        $html .= self::membership($snapshot);
        $html .= self::documents($snapshot, $documents);
        $html .= self::privacy($snapshot);
        $html .= self::account($snapshot, $logoutUrl);
        $html .= '</div>';

        return $html;
    }

    private static function currentUrl(): string
    {
        $permalink = function_exists('get_permalink') ? get_permalink() : false;

        if (is_string($permalink) && $permalink !== '') {
            return $permalink;
        }

        return function_exists('home_url') ? home_url('/') : '/';
    }

    private static function details(MemberAreaSnapshot $snapshot): string
    {
        $html = '<section><h2>' . self::phrase('My details') . '</h2><dl>';
        $html .= self::pair('Name', $snapshot->name);
        $html .= self::pair('Contact email', $snapshot->contactEmail);
        $html .= self::pair('Account email', $snapshot->accountEmail);

        if ($snapshot->birthDate !== null) {
            $html .= self::pair('Birth date', $snapshot->birthDate);
        }

        $html .= '</dl>';

        if ($snapshot->emailMismatch) {
            $html .= '<p>' . self::phrase('Your association contact email and your WordPress account email are different.') . '</p>';
        }

        $html .= '<p>' . self::phrase('Contact the association if your information needs to be corrected.') . '</p></section>';

        return $html;
    }

    private static function membership(MemberAreaSnapshot $snapshot): string
    {
        $html = '<section><h2>' . self::phrase('My membership') . '</h2>';
        $html .= '<p>' . self::phrase($snapshot->activeMember
            ? 'Membership status: Active'
            : 'Membership status: Not currently active') . '</p>';

        if ($snapshot->coverages !== []) {
            $html .= '<ul>';

            foreach ($snapshot->coverages as $coverage) {
                $html .= '<li><dl>';
                $html .= self::pair('Membership number', $coverage->number);
                $html .= self::pair('Membership type', self::kind($coverage->kind));
                $html .= self::pair('From', $coverage->from);
                $html .= self::pair('To', $coverage->to ?? self::translated('Ongoing'));
                $html .= '</dl>';

                if ($coverage->timing === MemberAreaTiming::Future) {
                    $html .= '<p>' . self::phrase('Future') . '</p>';
                }

                $html .= '</li>';
            }

            $html .= '</ul>';
        }

        $html .= '</section>';

        return $html;
    }

    /**
     * @param list<array{title: string, url: string}>|null $documents
     */
    private static function documents(MemberAreaSnapshot $snapshot, ?array $documents): string
    {
        $html = '<section><h2>' . self::phrase('My documents') . '</h2>';

        if (! $snapshot->activeMember || $documents === null) {
            return $html . '<p>' . self::phrase('Member-only documents are available while your individual membership is active.') . '</p></section>';
        }

        if ($documents === []) {
            return $html . '<p>' . self::phrase('No member documents.') . '</p></section>';
        }

        $html .= '<ul>';

        foreach ($documents as $document) {
            $html .= '<li><a href="' . self::href($document['url']) . '">' . self::text($document['title']) . '</a></li>';
        }

        return $html . '</ul></section>';
    }

    private static function privacy(MemberAreaSnapshot $snapshot): string
    {
        $html = '<section><h2>' . self::phrase('My privacy') . '</h2>';
        $html .= '<p>' . self::phrase('The association currently has these basic categories linked to your member record:') . '</p><ul>';
        $html .= '<li>' . self::phrase('Contact details') . '</li>';
        $html .= '<li>' . self::phrase($snapshot->birthDate === null ? 'Birth date: Not recorded' : 'Birth date: Recorded') . '</li>';
        $html .= '<li>' . self::phrase($snapshot->personalIdentityRecorded ? 'Personal identity number: Recorded' : 'Personal identity number: Not recorded') . '</li>';
        $html .= '<li>' . self::phrase('Membership history') . '</li>';
        $html .= '<li>' . self::phrase('WordPress account link') . '</li>';
        $html .= '</ul><p>' . self::phrase('This is a summary from Mina sidor. You can contact the association if information is incorrect or if you want to exercise privacy rights.') . '</p></section>';

        return $html;
    }

    private static function account(MemberAreaSnapshot $snapshot, string $logoutUrl): string
    {
        return '<section><h2>' . self::phrase('My account') . '</h2><p>'
            . self::phrase('Logged in as')
            . ' ' . self::text($snapshot->accountEmail)
            . '</p><p><a href="' . self::href($logoutUrl) . '">'
            . self::phrase('Log out')
            . '</a></p></section>';
    }

    private static function pair(string $label, string $value): string
    {
        return '<dt>' . self::phrase($label) . '</dt><dd>' . self::text($value) . '</dd>';
    }

    private static function kind(MembershipKind $kind): string
    {
        return self::translated(match ($kind) {
            MembershipKind::Ordinary => 'Ordinary',
            MembershipKind::Youth => 'Youth',
            MembershipKind::Family => 'Family',
            MembershipKind::Company => 'Company',
        });
    }

    private static function phrase(string $value): string
    {
        return self::text(self::translated($value));
    }

    private static function translated(string $value): string
    {
        return function_exists('__') ? (string) __($value, 'foreningsplugin') : $value;
    }

    private static function text(string $value): string
    {
        return function_exists('esc_html')
            ? esc_html($value)
            : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function href(string $value): string
    {
        return function_exists('esc_url')
            ? esc_url($value)
            : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
