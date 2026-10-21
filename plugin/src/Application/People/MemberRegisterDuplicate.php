<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use RuntimeException;

/**
 * A row's membership number or email was saved by someone else after the preview.
 * The message is NUMBER or EMAIL.
 */
final class MemberRegisterDuplicate extends RuntimeException
{
    public const NUMBER = 'number';

    public const EMAIL = 'email';
}
