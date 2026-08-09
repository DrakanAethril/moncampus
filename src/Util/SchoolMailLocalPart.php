<?php

declare(strict_types=1);

namespace App\Util;

/**
 * What a School mail address local part is allowed to be.
 *
 * Gathered here rather than scattered between the generator and the entity because both must answer
 * exactly the same: App\Service\StudentMailAddressGenerator must never build an address that
 * App\Entity\EmailAlias would reject, and the other way round.
 */
final class SchoolMailLocalPart
{
    /**
     * Local parts no student may be given, reception being catch-all: on this domain, an address
     * taken is taken for the whole school.
     *
     * - `dmarc` is already served by our own SES receipt rule, which files authentication reports
     *   under a dedicated S3 prefix before the catch-all.
     * - `postmaster` and `abuse` are standard service addresses (RFC 2142) every domain must be able
     *   to honour, and which an outside correspondent - or a mail provider - may call on at any
     *   time.
     *
     * @var list<string>
     */
    public const array RESERVED = ['dmarc', 'postmaster', 'abuse'];

    public static function isReserved(string $localPart): bool
    {
        return \in_array(mb_strtolower(trim($localPart)), self::RESERVED, true);
    }

    /**
     * Requires the `something.something` shape, imposed on hand-typed aliases.
     *
     * The goal is to make it impossible to create addresses passing themselves off as a school
     * service - `comptabilite@`, `direction@`, `scolarite@`. Seen by a company they would be
     * indistinguishable from an official address, while they point at a student's mailbox. The dot
     * forces a shape that reads as a person or as an explicit scope (`stages.sio2`), never as an
     * institution.
     *
     * It also forbids a leading/trailing dot and consecutive dots, which some mail servers reject
     * (RFC 5321: a dot can be neither first, nor last, nor doubled outside quotes).
     */
    public static function hasRequiredDot(string $localPart): bool
    {
        return 1 === preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/', $localPart);
    }

    /** Le jeu de caractères admis, commun à toutes les origines. */
    public static function isWellFormed(string $localPart): bool
    {
        if ('' === $localPart || \strlen($localPart) > 64) {
            return false;
        }

        return 1 === preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*$/', $localPart);
    }
}
