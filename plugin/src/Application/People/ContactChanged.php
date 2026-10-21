<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

use RuntimeException;

/**
 * The person's contact details changed after the form was opened, so the edit was not
 * saved over them.
 */
final class ContactChanged extends RuntimeException
{
}
