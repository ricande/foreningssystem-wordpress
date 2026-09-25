<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Setup;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Association\AssociationProfile;
use InvalidArgumentException;

/**
 * First-run setup wizard application rules. Domain writes go through canonical services.
 */
final class SetupWizard
{
    public function __construct(
        private readonly SetupState $state,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function guard(): void
    {
        if (! $this->authorizer->allows(Capabilities::MANAGE_ASSOCIATION)) {
            throw new NotAllowed(Capabilities::MANAGE_ASSOCIATION);
        }
    }

    public function isComplete(): bool
    {
        return $this->state->isComplete();
    }

    public function currentStep(): string
    {
        return SetupStep::normalize($this->state->step());
    }

    public function resolveStep(?string $requested): string
    {
        return SetupStep::normalize($requested ?? $this->state->step());
    }

    public function advanceTo(string $step): void
    {
        $this->guard();
        $this->state->setStep(SetupStep::normalize($step));
    }

    public function goNext(?string $from = null): string
    {
        $this->guard();
        $next = SetupStep::next($from ?? $this->currentStep());
        $this->state->setStep($next);

        return $next;
    }

    public function goBack(?string $from = null): string
    {
        $this->guard();
        $previous = SetupStep::previous($from ?? $this->currentStep());
        $this->state->setStep($previous);

        return $previous;
    }

    public function associationNameAllowsProgress(AssociationProfile $profile): bool
    {
        return trim($profile->name()) !== '';
    }

    public function assertAssociationNameForProgress(AssociationProfile $profile): void
    {
        if (! $this->associationNameAllowsProgress($profile)) {
            throw new InvalidArgumentException('The association name is required to continue setup.');
        }
    }

    public function canFinish(AssociationProfile $profile): bool
    {
        return $this->associationNameAllowsProgress($profile);
    }

    public function finish(AssociationProfile $profile): void
    {
        $this->guard();

        if (! $this->canFinish($profile)) {
            throw new InvalidArgumentException('The association name is required to finish setup.');
        }

        $this->state->complete();
    }

    public function reopenDoesNotReset(): void
    {
        // Reopening the guide after completion must keep setup version at CURRENT.
        if (! $this->state->isComplete()) {
            return;
        }

        $this->state->setStep(SetupStep::WELCOME);
    }
}
