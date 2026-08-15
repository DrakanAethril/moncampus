<?php

declare(strict_types=1);

namespace App\Tests\Referential;

use App\Referential\ReferentialLabelMatcher;
use PHPUnit\Framework\TestCase;

/**
 * The matcher decides which existing row a catalogue entry lands on, so a false positive writes a
 * competency's content onto a different competency. Every rule it has is pinned here.
 */
class ReferentialLabelMatcherTest extends TestCase
{
    private ReferentialLabelMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new ReferentialLabelMatcher();
    }

    public function testMatchesIgnoringCaseAccentsAndSpacing(): void
    {
        self::assertTrue($this->matcher->matches(
            'Développer des composants métier',
            "  developper   DES composants  METIER \n",
        ));
    }

    /**
     * The referential document writes the typographic apostrophe, the database the straight one -
     * the same trap the Notion sequence import hit.
     */
    public function testMatchesEitherApostrophe(): void
    {
        self::assertTrue($this->matcher->matches(
            'Contribuer à la gestion d’un projet informatique',
            "Contribuer à la gestion d'un projet informatique",
        ));
    }

    public function testMatchesRegardlessOfTrailingPunctuation(): void
    {
        self::assertTrue($this->matcher->matches(
            'Administrer et sécuriser les infrastructures réseaux.',
            'Administrer et sécuriser les infrastructures réseaux',
        ));
    }

    /**
     * Singular/plural divergences are real in this database ("interfaces utilisateurs" against the
     * document's "interfaces utilisateur") but they are NOT normalised away - two competencies can
     * legitimately differ by one word. The catalogue declares the variant explicitly instead.
     */
    public function testDoesNotMatchOnPluralAlone(): void
    {
        self::assertFalse($this->matcher->matches(
            'Développer des interfaces utilisateur',
            'Développer des interfaces utilisateurs',
        ));
    }

    public function testDoesNotMatchDifferentCompetencies(): void
    {
        self::assertFalse($this->matcher->matches(
            'Développer des composants métier',
            "Développer des composants d'accès aux données SQL et NoSQL",
        ));
    }

    public function testFindsTheDeclaredAliasWhenTheMainLabelIsAbsent(): void
    {
        $candidates = [
            7 => 'Développer des interfaces utilisateurs',
            9 => 'Développer des composants métier',
        ];

        self::assertSame(7, $this->matcher->findKey(
            $candidates,
            'Développer des interfaces utilisateur',
            ['Développer des interfaces utilisateurs'],
        ));
    }

    public function testFindsNothingRatherThanGuessing(): void
    {
        $candidates = [3 => 'Administrer et sécuriser les infrastructures systèmes'];

        self::assertNull($this->matcher->findKey($candidates, 'Communication en langue anglaise', []));
    }

    /**
     * Two rows normalising to the same label is a database problem, not something to pick a winner
     * from - the import must report it and leave both alone.
     */
    public function testRefusesAnAmbiguousMatch(): void
    {
        $candidates = [
            4 => 'Développer des composants métier',
            5 => 'developper des composants metier',
        ];

        self::assertNull($this->matcher->findKey($candidates, 'Développer des composants métier', []));
    }

    public function testResolvesATeacherFromInitialAndSurname(): void
    {
        $teachers = [
            2 => ['firstname' => 'Sébastien', 'lastname' => 'Tharaud'],
            3 => ['firstname' => 'Florent', 'lastname' => 'Sautour'],
        ];

        self::assertSame(3, $this->matcher->findTeacherKey($teachers, 'F. Sautour'));
        self::assertSame(2, $this->matcher->findTeacherKey($teachers, 'S. Tharaud'));
    }

    /** Three Sautour share a surname in this referential; only the initial separates them. */
    public function testDoesNotResolveATeacherOnSurnameAlone(): void
    {
        $teachers = [3 => ['firstname' => 'Florent', 'lastname' => 'Sautour']];

        self::assertNull($this->matcher->findTeacherKey($teachers, 'V. Sautour'));
        self::assertNull($this->matcher->findTeacherKey($teachers, 'A. Sautour'));
    }

    public function testDoesNotResolveAnAmbiguousTeacher(): void
    {
        $teachers = [
            3 => ['firstname' => 'Florent', 'lastname' => 'Sautour'],
            8 => ['firstname' => 'Fabienne', 'lastname' => 'Sautour'],
        ];

        self::assertNull($this->matcher->findTeacherKey($teachers, 'F. Sautour'));
    }

    public function testResolvesATeacherIgnoringAccentsAndSpacing(): void
    {
        $teachers = [6 => ['firstname' => 'Alain', 'lastname' => 'Théron']];

        self::assertSame(6, $this->matcher->findTeacherKey($teachers, 'A. Theron'));
    }
}
