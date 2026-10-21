<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

/**
 * Serializes writes to the member register.
 *
 * Membership rules are checked by reading the register and then writing to it: no
 * overlapping periods, no overlapping coverage for one person across memberships, one
 * holder of a membership number, board assignments covered by membership. Two officers
 * who save at the same time, or one double-click on Save, must not both pass the check.
 * Every register write runs its checks and its transaction inside this claim. See
 * ADR-0031.
 */
interface MemberRegisterLock
{
    /**
     * Runs the work while holding the register. The claim is released when the work
     * returns or throws, after its transaction has committed or rolled back.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     * @throws MemberRegisterBusy when another write holds the register for too long.
     */
    public function hold(callable $work): mixed;
}
