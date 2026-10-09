<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What App\Service\InboundMailProcessor did with one object of `incoming/`.
 *
 * None of these is a failure - a failure is an exception, which leaves the SQS message in the
 * queue. The three are told apart for the reconciliation's sake: only `Stored` wrote a row, so only
 * `Stored` is a mail it recovered.
 */
enum InboundMailOutcome
{
    /** A row was written: the mail is new to the platform. */
    case Stored;

    /** This very object already has its row (an SQS redelivery, a second pass). */
    case AlreadyStored;

    /**
     * Another row carries the same Message-ID, so nothing was written - and nothing ever will be
     * under this object's key. The usual case is a student's own send coming back in: they copied
     * their school address, and the mail they sent is already there as the send.
     */
    case DuplicateMessageId;
}
