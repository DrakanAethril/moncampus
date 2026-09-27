<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What checking a service password found (App\Security\ExternalServicePasswords::check()).
 */
enum ExternalServiceSignIn
{
    case Accepted;

    /** No such account, a deactivated one, or the wrong password - told apart to nobody. */
    case Refused;

    /** The account exists and the password is right for nothing: none was ever set for this service. */
    case NoServicePassword;

    /**
     * The right service password - which has since become the establishment password, the person
     * having changed the latter to it. Refused all the same: the rule holds at every sign-in, not
     * only when the service password was chosen.
     */
    case SameAsPlatformPassword;
}
