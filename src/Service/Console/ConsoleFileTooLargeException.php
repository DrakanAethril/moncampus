<?php

declare(strict_types=1);

namespace App\Service\Console;

/**
 * Past 8 MiB, the honest answer is a link the machine downloads for itself.
 *
 * A clipboard is not a file transfer service: the payload travels base64-encoded inside one SSH
 * command, which inflates it by a third and holds a worker for as long as it takes. See
 * App\Service\Console\GuestFileDrop.
 */
class ConsoleFileTooLargeException extends \RuntimeException
{
}
