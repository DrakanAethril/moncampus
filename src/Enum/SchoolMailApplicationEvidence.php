<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What tied an incoming mail back to a démarche when In-Reply-To could not
 * (App\Service\SchoolMailApplicationRecovery).
 *
 * Two ways only, and the order is the order of certainty: the mail quotes the identifier of a send,
 * or it quotes the address that send was written to. Anything weaker - the same correspondent, a
 * close enough date - is exactly the coincidence App\Service\InboundMailProcessor refuses to file on.
 */
enum SchoolMailApplicationEvidence: string
{
    case MessageId = 'message_id';
    case Address = 'address';
}
