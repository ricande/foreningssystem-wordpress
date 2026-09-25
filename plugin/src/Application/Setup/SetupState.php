<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Setup;

/**
 * Versioned local setup status. Not tied to the database schema version.
 */
interface SetupState
{
    public const CURRENT_SETUP_VERSION = 1;

    public function version(): int;

    public function optionExists(): bool;

    public function isComplete(): bool;

    public function step(): string;

    public function setStep(string $step): void;

    public function clearProgress(): void;

    public function redirectPending(): bool;

    public function markRedirectPending(): void;

    public function clearRedirectPending(): void;

    public function markFreshIncomplete(): void;

    public function adoptCompleted(): void;

    public function complete(): void;
}
