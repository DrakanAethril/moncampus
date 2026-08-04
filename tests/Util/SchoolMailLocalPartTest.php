<?php

namespace App\Tests\Util;

use App\Util\SchoolMailLocalPart;
use PHPUnit\Framework\TestCase;

/**
 * Ce qu'une partie locale d'adresse Courrier école a le droit d'être.
 *
 * La règle du point mérite d'être épinglée pour ce qu'elle empêche plus que pour ce qu'elle
 * autorise : la réception étant en catch-all, créer un alias revient à fabriquer une identité
 * d'expédition sur le domaine de l'établissement. Sans elle, `comptabilite@etu.beaupeyrat.org`
 * serait indiscernable d'une adresse officielle pour l'entreprise qui la reçoit.
 */
class SchoolMailLocalPartTest extends TestCase
{
    public function testSingleWordAddressesAreRejected(): void
    {
        // Le cas que la règle existe pour empêcher : des adresses qui se lisent comme un service
        // de l'établissement alors qu'elles pointent vers la boîte d'un élève.
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
        // Refusés par certains serveurs de messagerie (RFC 5321 : hors guillemets, un point ne peut
        // être ni premier, ni dernier, ni doublé). Une adresse qu'on ne peut pas joindre est pire
        // qu'une adresse refusée à la saisie.
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
        // Sans point : la forme de l'alias de login, valide en soi - c'est la règle du point,
        // appliquée à la seule origine « manuelle », qui l'écarterait.
        self::assertTrue(SchoolMailLocalPart::isWellFormed('croux'));

        self::assertFalse(SchoolMailLocalPart::isWellFormed(''));
        self::assertFalse(SchoolMailLocalPart::isWellFormed('Camille.Roux'));
        self::assertFalse(SchoolMailLocalPart::isWellFormed('camille roux'));
        self::assertFalse(SchoolMailLocalPart::isWellFormed('camille+stage'));
        self::assertFalse(SchoolMailLocalPart::isWellFormed(str_repeat('a', 65)));
    }
}
