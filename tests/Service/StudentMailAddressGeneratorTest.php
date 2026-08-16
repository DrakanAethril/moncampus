<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\EmailAliasRepository;
use App\Service\StudentMailAddressGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The composition rule of the « prenom.nom » addresses of the Courrier école.
 *
 * It deserves pinning down here more than most, for a reason that has nothing technical about it:
 * the address produced ends up printed on CVs and recorded in company address books. Once issued,
 * it does not change. A quiet change of transliteration would therefore break no screen test, but
 * would produce two generations of students with different conventions - and that cannot be undone.
 */
class StudentMailAddressGeneratorTest extends TestCase
{
    public function testAccentsAreFoldedAndCaseLowered(): void
    {
        self::assertSame('chloe.dupont', $this->generate('Chloé', 'Dupont'));

        // The fallback is a plain stripping of diacritics, not a German-style romanisation:
        // `Ü` gives `u` and not `ue`. Same mechanism (iconv //TRANSLIT) as App\Service\LoginGenerator,
        // so login and address stay consistent for a given student.
        self::assertSame('joel.muller', $this->generate('JOËL', 'MÜLLER'));
    }

    public function testHyphensSurviveButSpacesGlueParticles(): void
    {
        // A compound first name keeps its hyphen, a particle sticks to the surname: that is the rule
        // settled with the school, and the two halves are not treated alike.
        self::assertSame('jean-pierre.legall', $this->generate('Jean-Pierre', 'Le Gall'));
        self::assertSame('shirine.elhani', $this->generate('Shirine', 'El Hani'));
    }

    public function testOnlyTheFirstGivenNameIsKept(): void
    {
        // A space does not mean the same thing on either side of the dot: in a surname it separates
        // a particle from its name, which form a whole and are stuck together; in a first name it
        // separates given names, of which only the first is the one in everyday use.
        self::assertSame('mouhamadoun.waigalo', $this->generate('Mouhamadoun Aly', 'Waigalo'));
        self::assertSame('tity.bassekanounga', $this->generate('Tity Gabriel', 'Basseka Nounga'));

        // A hyphen still marks a compound first name, not two first names: it does not trigger the truncation.
        self::assertSame('jean-pierre.martin', $this->generate('Jean-Pierre', 'Martin'));
    }

    public function testApostrophesDisappear(): void
    {
        self::assertSame('chloe.darcy', $this->generate('Chloé', "d'Arcy"));
        self::assertSame('marie.ohara', $this->generate('Marie', "O'Hara"));
    }

    public function testStrayHyphensNeverLeakIntoTheAddress(): void
    {
        // Sloppy input must not produce a malformed address, which some mail servers would
        // reject.
        self::assertSame('jean.martin', $this->generate('-Jean-', '--Martin--'));
    }

    public function testAMissingHalfStillYieldsAUsableAddress(): void
    {
        // No orphan dot at the front, nor at the back: an incomplete record still gives a valid
        // address, even if a less legible one.
        self::assertSame('martin', $this->generate('', 'Martin'));
        self::assertSame('jean', $this->generate('Jean', ''));
    }

    public function testRealHomonymsAreNumberedFromTwo(): void
    {
        $generator = $this->generatorWithExisting(['camille.roux']);

        self::assertSame('camille.roux2', $generator->generateFor($this->user('Camille', 'Roux')));
    }

    public function testTwoHomonymsInTheSameBatchDoNotCollide(): void
    {
        // The uniqueness check is a query, so the second student of a same batch would not see the
        // first as long as no flush has happened. Without the in-memory reservation, both would
        // receive the same address and collide on the unique constraint.
        $generator = $this->generatorWithExisting([]);

        self::assertSame('camille.roux', $generator->generateFor($this->user('Camille', 'Roux')));
        self::assertSame('camille.roux2', $generator->generateFor($this->user('Camille', 'Roux')));
        self::assertSame('camille.roux3', $generator->generateFor($this->user('Camille', 'Roux')));
    }

    public function testServiceAddressesAreNeverHandedToAStudent(): void
    {
        $generator = $this->generatorWithExisting([]);

        // Reception is catch-all: these addresses belong to the domain, not to a person.
        // `dmarc` is already served by an SES rule that files the authentication reports;
        // `postmaster` and `abuse` are standardised by RFC 2142.
        self::assertFalse($generator->isAvailable('dmarc'));
        self::assertFalse($generator->isAvailable('postmaster'));
        self::assertFalse($generator->isAvailable('abuse'));

        // And a student whose name would compose one of them is numbered, not refused.
        self::assertSame('abuse2', $generator->generateFor($this->user('', 'Abuse')));
    }

    public function testAnEmptyNameIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->generate('', '');
    }

    private function generate(string $firstname, string $lastname): string
    {
        return $this->generatorWithExisting([])->generateFor($this->user($firstname, $lastname));
    }

    /** @param list<string> $existing */
    private function generatorWithExisting(array $existing): StudentMailAddressGenerator
    {
        // A stub and not a mock: we do not check *how* the repository is called, only what it
        // answers - the subject of the test is the composition rule, not the database access.
        $repository = $this->createStub(EmailAliasRepository::class);
        $repository->method('localPartExists')
            ->willReturnCallback(static fn (string $localPart): bool => \in_array($localPart, $existing, true));

        return new StudentMailAddressGenerator($repository);
    }

    private function user(string $firstname, string $lastname): User
    {
        $user = new User('test');
        $user->setFirstname($firstname);
        $user->setLastname($lastname);

        return $user;
    }
}
