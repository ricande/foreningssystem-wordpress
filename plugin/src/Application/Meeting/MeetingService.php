<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\People\Transaction;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Meeting\Meeting;
use Foreningssystem\Domain\Meeting\MeetingLifecycle;
use Foreningssystem\Domain\Meeting\MeetingRepository;
use Foreningssystem\Domain\Meeting\MeetingStatus;
use Foreningssystem\Domain\Meeting\MeetingMoment;
use Foreningssystem\Domain\Meeting\MeetingType;
use Foreningssystem\Domain\Meeting\MeetingTypeRepository;

final class MeetingService
{
    public function __construct(
        private readonly MeetingTypeRepository $types,
        private readonly MeetingRepository $meetings,
        private readonly MeetingLifecycle $lifecycle,
        private readonly Authorizer $authorizer,
        private readonly Transaction $transaction,
    ) {
    }

    public function schedule(int $typeId, string $title, MeetingMoment $startsAt, string $place): int
    {
        $this->require(Capabilities::MANAGE_MEETINGS);
        $this->requireType($typeId);

        $saved = $this->transaction->run(function () use ($typeId, $title, $startsAt, $place): Meeting {
            return $this->meetings->add(new Meeting(null, $typeId, $title, $startsAt, $place, MeetingStatus::Planned));
        });
        $id = $saved->id();

        if ($id === null) {
            throw new \RuntimeException('The meeting was not saved.');
        }

        return $id;
    }

    public function start(int $meetingId): void
    {
        $this->requireAny([Capabilities::RECORD_MEETING, Capabilities::MANAGE_MEETINGS]);
        $meeting = $this->requireMeeting($meetingId);

        $this->transaction->run(function () use ($meeting): void {
            $this->meetings->save($this->lifecycle->start($meeting));
        });
    }

    public function markHeld(int $meetingId): void
    {
        $this->requireAny([Capabilities::RECORD_MEETING, Capabilities::MANAGE_MEETINGS]);
        $meeting = $this->requireMeeting($meetingId);

        $this->transaction->run(function () use ($meeting): void {
            $this->meetings->save($this->lifecycle->markHeld($meeting));
        });
    }

    public function updateHeader(int $meetingId, int $typeId, string $title, MeetingMoment $startsAt, string $place): void
    {
        $this->requireAny([Capabilities::RECORD_MEETING, Capabilities::MANAGE_MEETINGS]);
        $this->requireType($typeId);
        $meeting = $this->requireMeeting($meetingId);

        $this->transaction->run(function () use ($meeting, $typeId, $title, $startsAt, $place): void {
            $this->meetings->save($meeting->withHeader($typeId, $title, $startsAt, $place));
        });
    }

    /**
     * @return list<Meeting>
     */
    public function listMeetings(): array
    {
        $this->require(Capabilities::VIEW_INTERNAL_MEETINGS);

        return $this->meetings->all();
    }

    public function countWithStatus(MeetingStatus $status): int
    {
        $count = 0;

        foreach ($this->listMeetings() as $meeting) {
            if ($meeting->status() === $status) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<MeetingType>
     */
    public function types(): array
    {
        $this->require(Capabilities::VIEW_INTERNAL_MEETINGS);

        return $this->types->all();
    }

    private function require(string $capability): void
    {
        if (! $this->authorizer->allows($capability)) {
            throw new NotAllowed($capability);
        }
    }

    /**
     * @param list<string> $capabilities
     */
    private function requireAny(array $capabilities): void
    {
        foreach ($capabilities as $capability) {
            if ($this->authorizer->allows($capability)) {
                return;
            }
        }

        throw new NotAllowed($capabilities[0]);
    }

    private function requireType(int $typeId): MeetingType
    {
        $type = $this->types->find($typeId);

        if (! $type instanceof MeetingType || $type->id() === null) {
            throw new \RuntimeException('Meeting type was not found.');
        }

        return $type;
    }

    private function requireMeeting(int $meetingId): Meeting
    {
        $meeting = $this->meetings->find($meetingId);

        if (! $meeting instanceof Meeting) {
            throw new \RuntimeException('Meeting was not found.');
        }

        return $meeting;
    }
}
