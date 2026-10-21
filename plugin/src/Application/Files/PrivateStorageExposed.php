<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Files;

/**
 * The web server was seen serving the private directory, so no new file is written there.
 * See ADR-0038.
 */
final class PrivateStorageExposed extends \RuntimeException
{
}
