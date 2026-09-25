<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Settings\BuiltinStructure;
use Foreningssystem\Domain\Meeting\MeetingDuty;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MeetingType;
use Foreningssystem\Domain\Meeting\Presence;
use Foreningssystem\Domain\Meeting\RevisionState;

final class MeetingLabels
{
    public static function type(MeetingType $type): string
    {
        $source = BuiltinStructure::meetingSource($type->slug());

        return $source === null ? $type->name() : __($source, 'foreningsplugin');
    }

    public static function status(MeetingStatus $status): string
    {
        return match ($status) {
            MeetingStatus::Planned => __('Planned', 'foreningsplugin'),
            MeetingStatus::InProgress => __('In progress', 'foreningsplugin'),
            MeetingStatus::Held => __('Held', 'foreningsplugin'),
        };
    }

    public static function presence(Presence $presence): string
    {
        return match ($presence) {
            Presence::Present => __('Present', 'foreningsplugin'),
            Presence::Absent => __('Absent', 'foreningsplugin'),
            Presence::CoOpted => __('Adjunct', 'foreningsplugin'),
        };
    }

    public static function duty(MeetingDuty $duty): string
    {
        return match ($duty) {
            MeetingDuty::None => __('None', 'foreningsplugin'),
            MeetingDuty::Chair => __('Chair', 'foreningsplugin'),
            MeetingDuty::Adjuster => __('Adjuster', 'foreningsplugin'),
        };
    }

    public static function revision(RevisionState $state): string
    {
        return match ($state) {
            RevisionState::Draft => __('Draft', 'foreningsplugin'),
            RevisionState::UnderAdjustment => __('Under adjustment', 'foreningsplugin'),
            RevisionState::Finalized => __('Finalized', 'foreningsplugin'),
        };
    }
}
