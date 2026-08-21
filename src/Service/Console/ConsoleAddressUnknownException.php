<?php

declare(strict_types=1);

namespace App\Service\Console;

/**
 * There is no address to open a console on.
 *
 * Not a failure of the machine but of what is known about it: a guest created by hand in Proxmox
 * has no allocation in the registry and belongs to no batch, so nothing here knows where it
 * answers. The screen says the frontier of the device in so many words - MonCampus has no door on
 * a machine it did not install - rather than showing an SSH error about an empty host.
 */
class ConsoleAddressUnknownException extends \RuntimeException
{
}
