<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Application\Setup\SetupState;
use Foreningssystem\Application\Setup\SetupStep;
use Foreningssystem\Infrastructure\Persistence\MigrationException;

/**
 * WordPress options for setup version, wizard progress, and first-run redirect.
 */
final class WordpressSetupState implements SetupState
{
    public const OPTION_VERSION = 'assoc_setup_version';

    public const OPTION_STEP = 'assoc_setup_step';

    public const OPTION_REDIRECT_PENDING = 'assoc_setup_redirect_pending';

    public const OPTION_SUCCESS_NOTICE = 'assoc_setup_success_notice';

    public static function instance(): self
    {
        return new self();
    }

    public function version(): int
    {
        $value = get_option(self::OPTION_VERSION, 0);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    public function optionExists(): bool
    {
        return get_option(self::OPTION_VERSION, false) !== false;
    }

    public function isComplete(): bool
    {
        return $this->version() >= self::CURRENT_SETUP_VERSION;
    }

    public function step(): string
    {
        $value = get_option(self::OPTION_STEP, SetupStep::WELCOME);

        return SetupStep::normalize(is_string($value) ? $value : SetupStep::WELCOME);
    }

    public function setStep(string $step): void
    {
        update_option(self::OPTION_STEP, SetupStep::normalize($step), false);
    }

    public function clearProgress(): void
    {
        delete_option(self::OPTION_STEP);
    }

    public function redirectPending(): bool
    {
        return get_option(self::OPTION_REDIRECT_PENDING, '') === '1';
    }

    public function markRedirectPending(): void
    {
        update_option(self::OPTION_REDIRECT_PENDING, '1', false);
    }

    public function clearRedirectPending(): void
    {
        delete_option(self::OPTION_REDIRECT_PENDING);
    }

    public function markFreshIncomplete(): void
    {
        update_option(self::OPTION_VERSION, 0, false);
        $this->clearProgress();
        $this->markRedirectPending();
    }

    public function adoptCompleted(): void
    {
        update_option(self::OPTION_VERSION, self::CURRENT_SETUP_VERSION, false);
        $this->clearProgress();
        $this->clearRedirectPending();
    }

    public function complete(): void
    {
        update_option(self::OPTION_VERSION, self::CURRENT_SETUP_VERSION, false);
        $this->clearProgress();
        $this->clearRedirectPending();
        update_option(self::OPTION_SUCCESS_NOTICE, '1', false);
    }

    public function consumeSuccessNotice(): bool
    {
        if (get_option(self::OPTION_SUCCESS_NOTICE, '') !== '1') {
            return false;
        }

        delete_option(self::OPTION_SUCCESS_NOTICE);

        return true;
    }

    /**
     * Activation hook: inspect schema BEFORE migration, then record fresh vs existing adoption.
     *
     * @return array{fresh: bool, adopted: bool, pending: bool, migrated: bool}
     */
    public static function handleActivation(int $schemaBefore): array
    {
        $state = self::instance();
        $setupExisted = $state->optionExists();
        $setupBefore = $state->version();
        $migrated = false;

        try {
            WordpressMigrations::runner()->migrate();
            WordpressAccess::sync();
            $migrated = true;
        } catch (MigrationException) {
            return [
                'fresh' => false,
                'adopted' => false,
                'pending' => false,
                'migrated' => false,
            ];
        }

        if ($schemaBefore === 0 && ! $setupExisted) {
            $state->markFreshIncomplete();

            return [
                'fresh' => true,
                'adopted' => false,
                'pending' => true,
                'migrated' => true,
            ];
        }

        if ($schemaBefore > 0 && ! $setupExisted) {
            $state->adoptCompleted();

            return [
                'fresh' => false,
                'adopted' => true,
                'pending' => false,
                'migrated' => true,
            ];
        }

        if ($setupBefore < self::CURRENT_SETUP_VERSION) {
            $state->markRedirectPending();

            return [
                'fresh' => false,
                'adopted' => false,
                'pending' => true,
                'migrated' => true,
            ];
        }

        return [
            'fresh' => false,
            'adopted' => false,
            'pending' => false,
            'migrated' => true,
        ];
    }

    /**
     * Existing installs that received the wizard code without reactivation.
     * Fresh installs always write assoc_setup_version=0 on activate, so they are not adopted here.
     */
    public static function adoptPreWizardIfNeeded(): void
    {
        $schema = (new WordpressSchemaVersionStore())->current();
        $state = self::instance();

        if ($schema > 0 && ! $state->optionExists()) {
            $state->adoptCompleted();
        }
    }
}
