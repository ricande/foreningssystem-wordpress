<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Settings;

final class BuiltinStructure
{
    /**
     * @return list<string>
     */
    public static function boardSlugs(): array
    {
        return ['chair', 'treasurer', 'secretary', 'alternate', 'auditor', 'election_committee'];
    }

    /**
     * @return list<string>
     */
    public static function meetingSlugs(): array
    {
        return [
            'board_meeting',
            'annual_meeting',
            'extraordinary_annual_meeting',
            'member_meeting',
            'working_meeting',
        ];
    }

    public static function isBoard(string $slug): bool
    {
        return in_array($slug, self::boardSlugs(), true);
    }

    public static function isMeeting(string $slug): bool
    {
        return in_array($slug, self::meetingSlugs(), true);
    }

    public static function boardSource(string $slug): ?string
    {
        return self::sources()[$slug] ?? null;
    }

    public static function meetingSource(string $slug): ?string
    {
        return self::sources()[$slug] ?? null;
    }

    /**
     * English source labels and Swedish seed names for built-ins that exist.
     *
     * @param list<string> $presentSlugs
     * @return list<string>
     */
    public static function reservedBoardNames(array $presentSlugs): array
    {
        return self::reserved([
            'chair' => ['Chair', 'Ordförande'],
            'treasurer' => ['Treasurer', 'Kassör'],
            'secretary' => ['Secretary', 'Sekreterare'],
            'alternate' => ['Alternate', 'Suppleant'],
            'auditor' => ['Auditor', 'Revisor'],
            'election_committee' => ['Election committee', 'Valberedning'],
        ], $presentSlugs);
    }

    /**
     * @param list<string> $presentSlugs
     * @return list<string>
     */
    public static function reservedMeetingNames(array $presentSlugs): array
    {
        return self::reserved([
            'board_meeting' => ['Board meeting', 'Styrelsemöte'],
            'annual_meeting' => ['Annual meeting', 'Årsmöte'],
            'extraordinary_annual_meeting' => ['Extraordinary annual meeting', 'Extra årsmöte'],
            'member_meeting' => ['Member meeting', 'Medlemsmöte'],
            'working_meeting' => ['Working meeting', 'Arbetsmöte'],
        ], $presentSlugs);
    }

    /**
     * @return array<string, string>
     */
    private static function sources(): array
    {
        return [
            'chair' => 'Chair',
            'treasurer' => 'Treasurer',
            'secretary' => 'Secretary',
            'alternate' => 'Alternate',
            'auditor' => 'Auditor',
            'election_committee' => 'Election committee',
            'board_meeting' => 'Board meeting',
            'annual_meeting' => 'Annual meeting',
            'extraordinary_annual_meeting' => 'Extraordinary annual meeting',
            'member_meeting' => 'Member meeting',
            'working_meeting' => 'Working meeting',
        ];
    }

    /**
     * @param array<string, list<string>> $labels
     * @param list<string> $presentSlugs
     * @return list<string>
     */
    private static function reserved(array $labels, array $presentSlugs): array
    {
        $names = [];

        foreach ($presentSlugs as $slug) {
            foreach ($labels[$slug] ?? [] as $label) {
                $names[] = VisibleName::normalize($label);
            }
        }

        return array_values(array_unique($names));
    }
}
