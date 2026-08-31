<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\SchoolMailLocalPart;
use PHPUnit\Framework\TestCase;

/**
 * What a Courrier pro local part is allowed to be.
 *
 * The dot rule deserves pinning down for what it prevents more than for what it allows: reception
 * being catch-all, creating an alias amounts to manufacturing a sending identity on the school's
 * domain. Without it, `comptabilite@etu.beaupeyrat.org` would be indistinguishable from an official
 * address for the company receiving it.
 */
class SchoolMailLocalPartTest extends TestCase
{
    public function testSingleWordAddressesAreRejected(): void
    {
        // The case the rule exists to prevent: addresses that read as a department of the school when
        // they in fact point to a student's mailbox.
        self::assertFalse(SchoolMailLocalPart::hasRequiredDot('comptabilite'));
        self::assertFalse(SchoolMailLocalPart::hasRequiredDot('direction'));
        self::assertFalse(SchoolMailLocalPart::hasRequiredDot('scolarite'));
    }

    public function testAddressesReadingAsAPersonOrAScopeAreAccepted(): void
    {
        self::assertTrue(SchoolMailLocalPart::hasRequiredDot('camille.roux'));
        self::assertTrue(SchoolMailLocalPart::hasRequiredDot('stages.sio2'));
        self::assertTrue(SchoolMailLocalPart::hasRequiredDot('jean-pierre.legall'));
        self::assertTrue(SchoolMailLocalPart::hasRequiredDot('marie.claire.dupont'));
    }

    public function testDotsOnTheEdgeOrDoubledAreRejected(): void
    {
        // Refused by some mail servers (RFC 5321: outside quotes, a dot can be neither first, nor
        // last, nor doubled). An address that cannot be reached is worse than an address refused at
        // entry time.
        self::assertFalse(SchoolMailLocalPart::hasRequiredDot('.camille.roux'));
        self::assertFalse(SchoolMailLocalPart::hasRequiredDot('camille.roux.'));
        self::assertFalse(SchoolMailLocalPart::hasRequiredDot('camille..roux'));
    }

    public function testServiceAddressesStayReservedWhateverTheCase(): void
    {
        self::assertTrue(SchoolMailLocalPart::isReserved('dmarc'));
        self::assertTrue(SchoolMailLocalPart::isReserved('POSTMASTER'));
        self::assertTrue(SchoolMailLocalPart::isReserved(' abuse '));
        self::assertFalse(SchoolMailLocalPart::isReserved('abuse.dupont'));
    }

    public function testWellFormednessAcceptsTheLoginAliasButNotFancyCharacters(): void
    {
        // With no dot: the shape of the login alias, valid in itself - it is the dot rule, applied to
        // the « manual » origin alone, that would rule it out.
        self::assertTrue(SchoolMailLocalPart::isWellFormed('croux'));

        self::assertFalse(SchoolMailLocalPart::isWellFormed(''));
        self::assertFalse(SchoolMailLocalPart::isWellFormed('Camille.Roux'));
        self::assertFalse(SchoolMailLocalPart::isWellFormed('camille roux'));
        self::assertFalse(SchoolMailLocalPart::isWellFormed('camille+stage'));
        self::assertFalse(SchoolMailLocalPart::isWellFormed(str_repeat('a', 65)));
    }
}
