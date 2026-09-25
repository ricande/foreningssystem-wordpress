<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Association\AssociationProfile;
use InvalidArgumentException;

final class WordpressAssociationProfile
{
    public const OPTION_NAME = 'assoc_profile_name';

    public const OPTION_ORGANIZATION_NUMBER = 'assoc_profile_organization_number';

    public const OPTION_ADDRESS = 'assoc_profile_address';

    public const OPTION_EMAIL = 'assoc_profile_email';

    public const OPTION_PHONE = 'assoc_profile_phone';

    public const OPTION_LANGUAGE = 'assoc_profile_language';

    public const OPTION_LOGO = 'assoc_profile_logo_id';

    public const OPTION_MEMBERSHIP_YEAR_START = 'assoc_profile_membership_year_start';

    public static function load(): AssociationProfile
    {
        $start = self::start();

        try {
            return new AssociationProfile(
                self::text(self::OPTION_NAME, AssociationProfile::NAME_MAX),
                self::text(self::OPTION_ORGANIZATION_NUMBER, AssociationProfile::ORGANIZATION_NUMBER_MAX),
                self::text(self::OPTION_ADDRESS, AssociationProfile::ADDRESS_MAX),
                self::text(self::OPTION_EMAIL, AssociationProfile::EMAIL_MAX),
                self::text(self::OPTION_PHONE, AssociationProfile::PHONE_MAX),
                self::language(),
                self::logo(),
                $start[0],
                $start[1]
            );
        } catch (InvalidArgumentException) {
            return AssociationProfile::empty();
        }
    }

    public static function storedLogoAttachmentId(): ?int
    {
        return self::logo();
    }

    public static function save(AssociationProfile $profile): void
    {
        if (! current_user_can(Capabilities::MANAGE_ASSOCIATION)) {
            throw new NotAllowed(Capabilities::MANAGE_ASSOCIATION);
        }

        $logo = $profile->logoAttachmentId();

        if ($logo !== null && ! self::isImage($logo)) {
            throw new InvalidArgumentException('The logo must be an image in the media library.');
        }

        update_option(self::OPTION_NAME, $profile->name());
        update_option(self::OPTION_ORGANIZATION_NUMBER, $profile->organizationNumber());
        update_option(self::OPTION_ADDRESS, $profile->address());
        update_option(self::OPTION_EMAIL, $profile->email());
        update_option(self::OPTION_PHONE, $profile->phone());
        update_option(self::OPTION_LANGUAGE, $profile->language());
        update_option(self::OPTION_LOGO, $logo ?? 0);
        update_option(self::OPTION_MEMBERSHIP_YEAR_START, $profile->membershipYearStart());
    }

    private static function text(string $key, int $max): string
    {
        $value = get_option($key, '');

        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);

        return mb_strlen($value) > $max ? '' : $value;
    }

    private static function language(): string
    {
        $value = get_option(self::OPTION_LANGUAGE, AssociationProfile::LANGUAGE_SWEDISH);

        return $value === AssociationProfile::LANGUAGE_ENGLISH
            ? AssociationProfile::LANGUAGE_ENGLISH
            : AssociationProfile::LANGUAGE_SWEDISH;
    }

    private static function logo(): ?int
    {
        $value = get_option(self::OPTION_LOGO, 0);
        $id = is_numeric($value) ? (int) $value : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function start(): array
    {
        $value = get_option(self::OPTION_MEMBERSHIP_YEAR_START, '01-01');

        if (! is_string($value) || preg_match('/^(\d{2})-(\d{2})$/', $value, $match) !== 1) {
            return [1, 1];
        }

        return [(int) $match[1], (int) $match[2]];
    }

    private static function isImage(int $id): bool
    {
        $post = get_post($id);

        return $post instanceof \WP_Post
            && $post->post_type === 'attachment'
            && wp_attachment_is_image($id);
    }
}
