<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The directory could not be asked whether a password is the establishment one. Never read as
 * « no »: a service password that might be the establishment password is refused.
 */
final class PlatformPasswordCheckUnavailable extends \RuntimeException
{
}
