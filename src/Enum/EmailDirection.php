<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Direction of a "School mail" message: received from outside through SES inbound, or emitted by
 * the platform on a student's behalf through SES SendRawEmail.
 *
 * Both directions live inside App\Entity\EmailMessage rather than in two tables, because linking a
 * reply to the mail it answers (In-Reply-To -> Message-ID) is a self-join on that table.
 */
enum EmailDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
