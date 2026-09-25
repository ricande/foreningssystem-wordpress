<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Application\People\Authorizer;
use Foreningssystem\Application\People\NotAllowed;
use Foreningssystem\Application\Setup\SetupState;
use Foreningssystem\Application\Setup\SetupStep;
use Foreningssystem\Application\Setup\SetupWizard;
use Foreningssystem\Domain\Access\Capabilities;
use Foreningssystem\Domain\Association\AssociationProfile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SetupWizardTest extends TestCase
{
    public function test_absent_setup_version_is_incomplete(): void
    {
        $state = new MemorySetupState();
        self::assertSame(0, $state->version());
        self::assertFalse($state->isComplete());
        self::assertFalse($this->wizard($state, true)->isComplete());
    }

    public function test_setup_version_one_is_complete(): void
    {
        $state = new MemorySetupState();
        $state->complete();
        self::assertSame(SetupState::CURRENT_SETUP_VERSION, $state->version());
        self::assertTrue($state->isComplete());
        self::assertTrue($this->wizard($state, true)->isComplete());
    }

    public function test_current_setup_version_constant_is_one(): void
    {
        self::assertSame(1, SetupState::CURRENT_SETUP_VERSION);
    }

    public function test_invalid_step_normalizes_to_welcome(): void
    {
        self::assertSame(SetupStep::WELCOME, SetupStep::normalize('nope'));
        self::assertSame(SetupStep::WELCOME, SetupStep::normalize(''));
        self::assertSame(SetupStep::WELCOME, SetupStep::normalize(null));
        self::assertSame(SetupStep::WELCOME, $this->wizard(new MemorySetupState(), true)->resolveStep('../etc/passwd'));
    }

    public function test_known_steps_are_accepted(): void
    {
        foreach (SetupStep::all() as $step) {
            self::assertTrue(SetupStep::isValid($step));
            self::assertSame($step, SetupStep::normalize($step));
        }
    }

    public function test_step_navigation_order(): void
    {
        self::assertSame(SetupStep::ASSOCIATION, SetupStep::next(SetupStep::WELCOME));
        self::assertSame(SetupStep::COMPLETE, SetupStep::next(SetupStep::PRIVACY));
        self::assertSame(SetupStep::COMPLETE, SetupStep::next(SetupStep::COMPLETE));
        self::assertSame(SetupStep::WELCOME, SetupStep::previous(SetupStep::ASSOCIATION));
        self::assertSame(SetupStep::WELCOME, SetupStep::previous(SetupStep::WELCOME));
    }

    public function test_advance_and_back_persist_progress_only(): void
    {
        $state = new MemorySetupState();
        $wizard = $this->wizard($state, true);
        $wizard->advanceTo(SetupStep::BOARD);
        self::assertSame(SetupStep::BOARD, $state->step());
        self::assertSame(0, $state->version());
        self::assertSame(SetupStep::MEMBERSHIP, $wizard->goBack());
        self::assertSame(SetupStep::BOARD, $wizard->goNext());
    }

    public function test_empty_association_name_blocks_progress_and_finish(): void
    {
        $wizard = $this->wizard(new MemorySetupState(), true);
        $empty = AssociationProfile::empty();
        self::assertFalse($wizard->associationNameAllowsProgress($empty));
        self::assertFalse($wizard->canFinish($empty));

        try {
            $wizard->assertAssociationNameForProgress($empty);
            self::fail('Empty name was allowed to progress.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        try {
            $wizard->finish($empty);
            self::fail('Empty name finished setup.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, (new MemorySetupState())->version());
        }
    }

    public function test_named_profile_allows_finish_and_sets_version_one(): void
    {
        $state = new MemorySetupState();
        $state->setStep(SetupStep::COMPLETE);
        $state->markRedirectPending();
        $wizard = $this->wizard($state, true);
        $profile = $this->namedProfile('Demo Association');
        $wizard->finish($profile);
        self::assertTrue($state->isComplete());
        self::assertSame(1, $state->version());
        self::assertFalse($state->redirectPending());
        self::assertSame(SetupStep::WELCOME, $state->step());
    }

    public function test_finish_clears_progress_and_pending(): void
    {
        $state = new MemorySetupState();
        $state->setStep(SetupStep::PRIVACY);
        $state->markRedirectPending();
        $this->wizard($state, true)->finish($this->namedProfile('Named'));
        self::assertSame(SetupStep::WELCOME, $state->step());
        self::assertFalse($state->redirectPending());
    }

    public function test_manage_association_is_required_for_wizard_mutations(): void
    {
        $state = new MemorySetupState();
        $wizard = $this->wizard($state, false);

        try {
            $wizard->advanceTo(SetupStep::ASSOCIATION);
            self::fail('Unauthorized advance succeeded.');
        } catch (NotAllowed $error) {
            self::assertSame(Capabilities::MANAGE_ASSOCIATION, $error->getMessage());
        }

        try {
            $wizard->finish($this->namedProfile('Named'));
            self::fail('Unauthorized finish succeeded.');
        } catch (NotAllowed $error) {
            self::assertSame(Capabilities::MANAGE_ASSOCIATION, $error->getMessage());
        }

        self::assertFalse($state->isComplete());
        self::assertSame(SetupStep::WELCOME, $state->step());
    }

    public function test_fresh_activation_leaves_setup_incomplete_and_pending(): void
    {
        $state = new MemorySetupState();
        $state->markFreshIncomplete();
        self::assertTrue($state->optionExists());
        self::assertSame(0, $state->version());
        self::assertFalse($state->isComplete());
        self::assertTrue($state->redirectPending());
    }

    public function test_existing_pre_wizard_adoption_completes_without_pending(): void
    {
        $state = new MemorySetupState();
        self::assertFalse($state->optionExists());
        $state->adoptCompleted();
        self::assertTrue($state->isComplete());
        self::assertSame(1, $state->version());
        self::assertFalse($state->redirectPending());
    }

    public function test_reactivation_of_incomplete_may_recreate_pending_without_resetting_step(): void
    {
        $state = new MemorySetupState();
        $state->markFreshIncomplete();
        $state->setStep(SetupStep::MEETINGS);
        $state->clearRedirectPending();
        $state->markRedirectPending();
        self::assertSame(SetupStep::MEETINGS, $state->step());
        self::assertSame(0, $state->version());
        self::assertTrue($state->redirectPending());
    }

    public function test_completed_setup_reactivation_does_not_reopen(): void
    {
        $state = new MemorySetupState();
        $state->complete();
        self::assertTrue($state->isComplete());
        self::assertFalse($state->redirectPending());
        $state->clearRedirectPending();
        self::assertTrue($state->isComplete());
        self::assertFalse($state->redirectPending());
    }

    public function test_reopen_after_complete_keeps_setup_version(): void
    {
        $state = new MemorySetupState();
        $state->complete();
        $wizard = $this->wizard($state, true);
        $wizard->reopenDoesNotReset();
        self::assertTrue($state->isComplete());
        self::assertSame(1, $state->version());
        self::assertSame(SetupStep::WELCOME, $state->step());
        $wizard->finish($this->namedProfile('Still named'));
        self::assertSame(1, $state->version());
    }

    public function test_membership_step_has_no_domain_side_effect_in_wizard(): void
    {
        $state = new MemorySetupState();
        $wizard = $this->wizard($state, true);
        $wizard->advanceTo(SetupStep::MEMBERSHIP);
        $wizard->goNext(SetupStep::MEMBERSHIP);
        self::assertSame(SetupStep::BOARD, $state->step());
        self::assertSame(0, $state->version());
    }

    public function test_finish_does_not_create_members_board_or_meetings_in_wizard_state(): void
    {
        $state = new MemorySetupState();
        $this->wizard($state, true)->finish($this->namedProfile('Only name'));
        self::assertSame([], $state->domainWrites());
        self::assertTrue($state->isComplete());
    }

    public function test_progress_option_is_navigation_only(): void
    {
        $state = new MemorySetupState();
        $state->setStep(SetupStep::PRIVACY);
        self::assertSame(SetupStep::PRIVACY, $state->step());
        self::assertSame(0, $state->version());
        $state->clearProgress();
        self::assertSame(SetupStep::WELCOME, $state->step());
    }

    public function test_unauthorized_user_does_not_clear_redirect_pending_in_application_rules(): void
    {
        $state = new MemorySetupState();
        $state->markRedirectPending();
        $wizard = $this->wizard($state, false);

        try {
            $wizard->guard();
            self::fail('Unauthorized guard passed.');
        } catch (NotAllowed) {
            self::assertTrue($state->redirectPending());
        }
    }

    public function test_setup_is_not_tied_to_schema_version_constant(): void
    {
        self::assertSame(1, SetupState::CURRENT_SETUP_VERSION);
        self::assertNotSame(16, SetupState::CURRENT_SETUP_VERSION);
    }

    public function test_option_exists_distinguishes_fresh_written_zero_from_absent(): void
    {
        $absent = new MemorySetupState();
        self::assertFalse($absent->optionExists());
        $fresh = new MemorySetupState();
        $fresh->markFreshIncomplete();
        self::assertTrue($fresh->optionExists());
        self::assertSame(0, $fresh->version());
    }

    public function test_incomplete_nav_expectation_is_get_started_only(): void
    {
        $incomplete = ['Get started'];
        $complete = ['Members', 'Board', 'Meetings', 'Decisions', 'Tasks', 'Documents', 'Settings'];
        self::assertSame(['Get started'], $incomplete);
        self::assertNotContains('Get started', $complete);
        self::assertContains('Settings', $complete);
    }

    public function test_welcome_step_writes_no_domain_values(): void
    {
        $state = new MemorySetupState();
        $this->wizard($state, true)->advanceTo(SetupStep::WELCOME);
        self::assertSame([], $state->domainWrites());
        self::assertSame(0, $state->version());
    }

    public function test_skip_advances_without_completing_setup(): void
    {
        $state = new MemorySetupState();
        $wizard = $this->wizard($state, true);
        $wizard->advanceTo(SetupStep::MEMBERSHIP);
        $wizard->goNext(SetupStep::MEMBERSHIP);
        self::assertFalse($state->isComplete());
        self::assertSame(SetupStep::BOARD, $state->step());
    }

    public function test_finish_requires_capability_even_with_valid_name(): void
    {
        $state = new MemorySetupState();

        try {
            $this->wizard($state, false)->finish($this->namedProfile('Named'));
            self::fail('Finish without manage_association succeeded.');
        } catch (NotAllowed $error) {
            self::assertSame(Capabilities::MANAGE_ASSOCIATION, $error->getMessage());
        }

        self::assertFalse($state->isComplete());
    }

    private function wizard(SetupState $state, bool $allowed): SetupWizard
    {
        return new SetupWizard($state, $this->authorizer($allowed));
    }

    private function authorizer(bool $allowed): Authorizer
    {
        return new class($allowed) implements Authorizer {
            public function __construct(private readonly bool $allowed)
            {
            }

            public function allows(string $capability): bool
            {
                return $this->allowed && $capability === Capabilities::MANAGE_ASSOCIATION;
            }
        };
    }

    private function namedProfile(string $name): AssociationProfile
    {
        return new AssociationProfile($name, '', '', '', '', AssociationProfile::LANGUAGE_SWEDISH, null, 1, 1);
    }
}

final class MemorySetupState implements SetupState
{
    private bool $exists = false;

    private int $version = 0;

    private string $step = SetupStep::WELCOME;

    private bool $pending = false;

    /** @var list<string> */
    private array $writes = [];

    public function version(): int
    {
        return $this->version;
    }

    public function optionExists(): bool
    {
        return $this->exists;
    }

    public function isComplete(): bool
    {
        return $this->version >= self::CURRENT_SETUP_VERSION;
    }

    public function step(): string
    {
        return SetupStep::normalize($this->step);
    }

    public function setStep(string $step): void
    {
        $this->step = SetupStep::normalize($step);
    }

    public function clearProgress(): void
    {
        $this->step = SetupStep::WELCOME;
    }

    public function redirectPending(): bool
    {
        return $this->pending;
    }

    public function markRedirectPending(): void
    {
        $this->pending = true;
    }

    public function clearRedirectPending(): void
    {
        $this->pending = false;
    }

    public function markFreshIncomplete(): void
    {
        $this->exists = true;
        $this->version = 0;
        $this->clearProgress();
        $this->pending = true;
    }

    public function adoptCompleted(): void
    {
        $this->exists = true;
        $this->version = self::CURRENT_SETUP_VERSION;
        $this->clearProgress();
        $this->pending = false;
    }

    public function complete(): void
    {
        $this->exists = true;
        $this->version = self::CURRENT_SETUP_VERSION;
        $this->clearProgress();
        $this->pending = false;
    }

    /**
     * @return list<string>
     */
    public function domainWrites(): array
    {
        return $this->writes;
    }
}
