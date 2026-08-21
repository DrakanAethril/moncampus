<?php

declare(strict_types=1);

namespace App\Service\Console;

use App\Service\Guest\GuestShellFactory;

/**
 * Under which name the console speaks.
 *
 * The console opens on `moncampus`, and that is not a comfort choice: it is the only account whose
 * credentials the platform holds. Opening on the reader's own account would need *their* password
 * on the machine, which MonCampus never stores. The consequence - the console can elevate - is
 * bounded by **which machines** it reaches, not by what may be done on them: somebody who can
 * already redeploy the whole batch is not held back by a refused `sudo`.
 */
final class ConsoleIdentity
{
    public const string PLATFORM_ACCOUNT = GuestShellFactory::SERVICE_ACCOUNT;
}
