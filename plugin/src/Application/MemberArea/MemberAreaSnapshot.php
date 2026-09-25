<?php

declare(strict_types=1);

namespace Foreningssystem\Application\MemberArea;

final class MemberAreaSnapshot
{
    /**
     * @param list<MemberAreaCoverage> $coverages
     */
    public function __construct(
        public readonly MemberAreaState $state,
        public readonly string $name,
        public readonly string $contactEmail,
        public readonly ?string $birthDate,
        public readonly bool $personalIdentityRecorded,
        public readonly string $accountEmail,
        public readonly bool $emailMismatch,
        public readonly bool $activeMember,
        public readonly array $coverages,
    ) {
    }

    public static function loggedOut(): self
    {
        return self::closed(MemberAreaState::LoggedOut);
    }

    public static function unlinked(): self
    {
        return self::closed(MemberAreaState::Unlinked);
    }

    public static function unavailable(): self
    {
        return self::closed(MemberAreaState::Unavailable);
    }

    public function isLinked(): bool
    {
        return $this->state === MemberAreaState::Linked;
    }

    private static function closed(MemberAreaState $state): self
    {
        return new self($state, '', '', null, false, '', false, false, []);
    }
}
