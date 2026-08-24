<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Thrown by LdapAccountRequestService when a request may not be posted - a gesture already under
 * way on the account, an administrator closing their own account, a login that is taken,
 * unchanged or malformed.
 *
 * Its message is a translation key, not a sentence: the caller is a controller which turns it into
 * a flash or a form error, and the reason has to reach the administrator - "it did not work" is the
 * one answer this feature must never give.
 */
class LdapAccountRequestException extends \RuntimeException
{
}
