<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use RuntimeException;

/**
 * Another write held the member register for longer than the wait. Nothing was saved,
 * and the same request can be sent again.
 */
final class MemberRegisterBusy extends RuntimeException
{
}
