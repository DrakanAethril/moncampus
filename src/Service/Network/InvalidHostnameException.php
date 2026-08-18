<?php

declare(strict_types=1);

namespace App\Service\Network;

/**
 * Thrown when a name that is about to become a machine's hostname does not satisfy hostname rules.
 *
 * Its own exception rather than a generic one because of where it is caught: the creation wizard
 * turns it into a field error on the single "name" input, which is the only place the person who
 * typed it can fix it. Proxmox itself would accept the name and boot a machine nobody can resolve.
 */
class InvalidHostnameException extends \InvalidArgumentException
{
}
