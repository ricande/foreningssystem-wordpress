<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

/**
 * System starting points. Choosing one copies its headings into a saved association template.
 * The catalog is not a meeting-template row, and a later edit here does not rewrite templates already saved.
 */
final class MeetingStarterCatalog
{
    /**
     * @return list<MeetingStarter>
     */
    public static function all(): array
    {
        return [
            new MeetingStarter('annual', 'Annual meeting', 'annual_meeting', [
                'Opening of the meeting',
                'Adoption of the voting list',
                'Election of the meeting chair',
                'Election of the meeting secretary',
                'Election of two adjusters',
                'Question of whether the meeting was duly convened',
                'Adoption of the agenda',
                'The board\'s activity report',
                'Financial report',
                'Auditor\'s report',
                'Adoption of the income statement and balance sheet',
                'Question of discharge from liability for the board',
                'Consideration of motions',
                'Consideration of proposals from the board',
                'Setting of membership fees',
                'Adoption of the plan of operations',
                'Adoption of the budget',
                'Election of the chair',
                'Election of the other board members',
                'Election of alternates',
                'Election of auditors',
                'Election of the nomination committee',
                'Other business',
                'Close of the meeting',
            ]),
            new MeetingStarter('board', 'Board meeting', 'board_meeting', [
                'Opening of the meeting',
                'Previous minutes',
                'Reports',
                'Other business',
                'Next meeting',
            ]),
            new MeetingStarter('association', 'Association meeting', 'member_meeting', [
                'Opening of the meeting',
                'Approval of the agenda',
                'Information',
                'Other business',
                'Close of the meeting',
            ]),
            new MeetingStarter('empty', 'Empty template', '', []),
        ];
    }

    public static function find(string $key): ?MeetingStarter
    {
        foreach (self::all() as $starter) {
            if ($starter->key() === $key) {
                return $starter;
            }
        }

        return null;
    }
}
