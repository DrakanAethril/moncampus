<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\PersonName;
use PHPUnit\Framework\TestCase;

/**
 * Splitting "NOM Prénom" and comparing two spellings of the same person.
 *
 * All the names below come from the school's real apprenticeship export - including the two that
 * break the naive "first word is the surname" rule (a double-barrelled surname written in capitals
 * after nothing, and a surname in two words). Getting either wrong has a cost the import cannot
 * repair afterwards: a tutor account created as "Andrade Nathan De", or worse, a second account for
 * someone the platform already knows.
 */
class PersonNameTest extends TestCase
{
    public function testCapitalsAreTheSurname(): void
    {
        self::assertSame(['lastname' => 'BRETONNET', 'firstname' => 'Paul'], PersonName::split('BRETONNET Paul'));
        self::assertSame(['lastname' => 'CHAMPCOMMUNAL--DEMONT', 'firstname' => 'Chloé'], PersonName::split('CHAMPCOMMUNAL--DEMONT Chloé'));
    }

    public function testASurnameInTwoWordsStaysWhole(): void
    {
        self::assertSame(['lastname' => 'DE ANDRADE', 'firstname' => 'Nathan'], PersonName::split('DE ANDRADE Nathan'));
    }

    public function testAllCapitalsFallsBackToFirstWordIsTheSurname(): void
    {
        self::assertSame(['lastname' => 'CUQUEMELLE', 'firstname' => 'JEAN FRANCOIS'], PersonName::split('CUQUEMELLE JEAN FRANCOIS'));
    }

    public function testNoCapitalsAlsoFallsBack(): void
    {
        self::assertSame(['lastname' => 'grosset', 'firstname' => 'gilles'], PersonName::split('grosset gilles'));
    }

    public function testAnEmptyCellSplitsIntoNothing(): void
    {
        self::assertSame(['lastname' => '', 'firstname' => ''], PersonName::split('   '));
    }

    public function testFoldIgnoresCaseAccentsAndOrder(): void
    {
        self::assertSame(PersonName::fold('DE ANDRADE Nathan'), PersonName::fold('Nathan', 'de Andrade'));
        self::assertSame(PersonName::fold('Chloé CHAMPCOMMUNAL--DEMONT'), PersonName::fold('CHAMPCOMMUNAL DEMONT', 'Chloe'));
    }

    public function testFoldKeepsDifferentPeopleApart(): void
    {
        self::assertNotSame(PersonName::fold('LEPETIT Arvid'), PersonName::fold('LEPETIT Constance'));
    }

    public function testFoldNormalisesCompanyNames(): void
    {
        self::assertSame(PersonName::fold('THERA SOFT'), PersonName::fold('Thera  Soft'));
        self::assertSame(PersonName::fold('AGC19 - Cerfance Corrèze'), PersonName::fold('AGC19 Cerfance Correze'));
    }
}
